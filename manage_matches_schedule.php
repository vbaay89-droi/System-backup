<?php
// This file is loaded via AJAX into Manage_Event.php
// It should NOT have session_start() or require_once 'config.php'
// as those are handled by the parent Manage_Event.php.

// --- Temporarily enable error reporting for debugging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- End temporary error reporting ---

// Ensure config.php is available for database operations
// This block ensures $conn is available, either from parent or by requiring it.
if (!isset($conn)) {
    require_once 'config.php';
    $close_conn_at_end = true; // Flag to close connection if opened here
} else {
    $close_conn_at_end = false;
}

// Always respond with JSON for AJAX actions if an action is specified via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'error' => ''];
}


// Fetch all events for dropdown (for adding/editing matches)
$all_events = [];
$event_category_map = []; // Initialize map here
$sql_events = "SELECT e.event_id, e.event_name, s.sport_name 
               FROM events e 
               LEFT JOIN sports s ON e.sport_id = s.sport_id 
               ORDER BY e.event_name ASC";

// Re-run the query (since we will modify the loop structure)
// NOTE: You should ensure config.php is included earlier to access $conn
$result_events = $conn->query($sql_events); 

if ($result_events) {
    // CORRECT: Loop once to populate both data structures
    while ($row = $result_events->fetch_assoc()) {
        $all_events[] = $row;
        // Use event_id as key and sport_name as value for the map
        $event_category_map[$row['event_id']] = $row['sport_name'] ?? 'N/A';
    }
}

// Fetch all teams for dropdown (for adding/editing matches)
$all_teams = [];
$sql_teams = "SELECT team_id, team_name, college FROM teams ORDER BY team_name ASC";
$result_teams = $conn->query($sql_teams);
if ($result_teams) {
    while ($row = $result_teams->fetch_assoc()) {
        $all_teams[] = $row;
    }
}

// Suggested match statuses
$match_statuses = ['Upcoming', 'Ongoing', 'Completed', 'Cancelled', 'Postponed'];

// --- Handle Add Match Form Submission (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_match') {
    $addEventId = $_POST['event_id'] ?? null;
    $addSportCategory = trim($_POST['sport_category'] ?? '');
    $addTeam1Id = $_POST['team1_id'] ?? null;
    $addTeam2Id = $_POST['team2_id'] ?? null;
    $addMatchDate = trim($_POST['match_date'] ?? '');
    $addMatchTime = trim($_POST['match_time'] ?? '');
    $addVenue = trim($_POST['venue'] ?? '');
    $addStatus = trim($_POST['status'] ?? 'Upcoming');

    // Basic validation
    if (empty($addEventId) ||
        empty($addSportCategory) ||
        empty($addTeam1Id) ||
        empty($addTeam2Id) ||
        empty($addMatchDate) ||
        empty($addMatchTime) ||
        empty($addVenue)) {

        $response['error'] = "Please fill in all required fields for the match.";
    } elseif ($addTeam1Id === $addTeam2Id) {
        $response['error'] = "Teams cannot play against themselves. Please select two different teams.";
    } else {
        // Validate date format
        $dateCheck = DateTime::createFromFormat('Y-m-d', $addMatchDate);
        if (!$dateCheck || $dateCheck->format('Y-m-d') !== $addMatchDate) {
            $response['error'] = "Invalid date format. Please use YYYY-MM-DD format.";
        } else {
            // Use proper prepared statement with all parameters as placeholders
            $sql_insert = "INSERT INTO matches (event_id, sport_category, team1_id, team2_id, match_date, match_time, venue, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert = $conn->prepare($sql_insert);
        

            if ($stmt_insert) {
                // Correct bind_param for add_match:
                // i (event_id), s (sport_category), i (team1_id), i (team2_id),
                // s (match_date), s (match_time), s (venue), s (status)
                $stmt_insert->bind_param("isiissss",
                    $addEventId,        // 1. i - event_id
                    $addSportCategory,  // 2. s - sport_category
                    $addTeam1Id,        // 3. i - team1_id
                    $addTeam2Id,        // 4. i - team2_id
                    $addMatchDate,      // 5. s - match_date
                    $addMatchTime,      // 6. s - match_time
                    $addVenue,          // 7. s - venue
                    $addStatus          // 8. s - status
                );

                if ($stmt_insert->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Match added successfully!";
                } else {
                    $response['error'] = "Error adding match: " . $stmt_insert->error;
                }
                $stmt_insert->close();
            } else {
                $response['error'] = "Database prepare error (add match): " . $conn->error;
            }
        }
    }
    echo json_encode($response);
    if (isset($close_conn_at_end) && $close_conn_at_end) { $conn->close(); }
    exit();
}

// --- Handle Add Result Submission (NEW AJAX ACTION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_result') {
    $matchId = $_POST['match_id'] ?? null;
    $resultStatus = trim($_POST['status'] ?? 'Completed'); // Default status to Completed
    // Note: score fields are intentionally kept separate from team IDs for clarity in forms
    $resultScore1 = !empty($_POST['score1']) || $_POST['score1'] === '0' ? (int)$_POST['score1'] : null;
    $resultScore2 = !empty($_POST['score2']) || $_POST['score2'] === '0' ? (int)$_POST['score2'] : null;
    $resultWinnerTeamId = !empty($_POST['winner_team_id']) ? (int)$_POST['winner_team_id'] : null;
    $resultTimeFinished = trim($_POST['time_finished'] ?? ''); // YYYY-MM-DDTHH:MM format

    // Basic validation for result
    if (empty($matchId) || $resultScore1 === null || $resultScore2 === null || empty($resultWinnerTeamId)) {
        $response['error'] = "Match ID, both scores, and a Winner Team are required to submit a result.";
    } elseif (empty($resultTimeFinished)) {
        $response['error'] = "Time Finished is required for results management.";
    } else {
        // Validate datetime-local format
        $datetimeCheck = DateTime::createFromFormat('Y-m-d\TH:i', $resultTimeFinished);
        if (!$datetimeCheck || $datetimeCheck->format('Y-m-d H:i') !== str_replace('T', ' ', $resultTimeFinished)) {
            $response['error'] = "Invalid 'Time Finished' format. Please use YYYY-MM-DDTHH:MM.";
        }

        if (empty($response['error'])) {
            // Convert time_finished to SQL format (YYYY-MM-DD HH:MM:SS)
            $sqlTimeFinished = str_replace('T', ' ', $resultTimeFinished) . ':00';

            // Update the match with result data and status
            $sql_update_result = "UPDATE matches SET
                                score1 = ?,
                                score2 = ?,
                                winner_team_id = ?,
                                time_finished = ?,
                                status = ? -- Status is updated along with the result
                                WHERE match_id = ?";

            $stmt_update_result = $conn->prepare($sql_update_result);

            if ($stmt_update_result) {
                // Binding: i (score1), i (score2), i (winner_team_id), s (time_finished), s (status), i (match_id)
                $stmt_update_result->bind_param("iiissi",
                    $resultScore1,
                    $resultScore2,
                    $resultWinnerTeamId,
                    $sqlTimeFinished,
                    $resultStatus,
                    $matchId
                );

                if ($stmt_update_result->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Match results added and status updated successfully!";
                } else {
                    $response['error'] = "Error adding match result: " . $stmt_update_result->error;
                }
                $stmt_update_result->close();
            } else {
                $response['error'] = "Database prepare error (add result): " . $conn->error;
            }
        }
    }
    echo json_encode($response);
    if (isset($close_conn_at_end) && $close_conn_at_end) { $conn->close(); }
    exit();
}

// --- Handle Update Match Form Submission (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_match') {
    $matchId = $_POST['match_id'] ?? null;
    $editEventId = $_POST['event_id'] ?? null;
    $editSportCategory = trim($_POST['sport_category'] ?? '');
    $editTeam1Id = $_POST['team1_id'] ?? null;
    $editTeam2Id = $_POST['team2_id'] ?? null;
    $editMatchDate = trim($_POST['match_date'] ?? '');
    $editMatchTime = trim($_POST['match_time'] ?? '');
    $editVenue = trim($_POST['venue'] ?? '');
    $editStatus = trim($_POST['status'] ?? 'Upcoming');
   

    // Basic validation
    if (empty($matchId) ||
        empty($editEventId) ||
        empty($editSportCategory) ||
        empty($editTeam1Id) ||
        empty($editTeam2Id) ||
        empty($editMatchDate) ||
        empty($editMatchTime) ||
        empty($editVenue)) {
        $response['error'] = "Please fill in all required fields for the match update.";
    } elseif ($editTeam1Id === $editTeam2Id) {
        $response['error'] = "Teams cannot play against themselves. Please select two different teams.";
    } else {
        // Validate date format for match_date
        $dateCheck = DateTime::createFromFormat('Y-m-d', $editMatchDate);
        if (!$dateCheck || $dateCheck->format('Y-m-d') !== $editMatchDate) {
            $response['error'] = "Invalid date format for match date. Please use YYYY-MM-DD format.";
        }

        if (empty($response['error'])) { // Only proceed if no validation errors so far
            // Construct the UPDATE query dynamically based on whether optional fields are provided
            $sql_update_parts = [
                "event_id = ?",
                "sport_category = ?",
                "team1_id = ?",
                "team2_id = ?",
                "match_date = ?",
                "match_time = ?",
                "venue = ?",
                "status = ?"
            ];
            $bind_types_update = "isiissss";
            $bind_params_update = [
                $editEventId,
                $editSportCategory,
                $editTeam1Id,
                $editTeam2Id,
                $editMatchDate,
                $editMatchTime,
                $editVenue,
                $editStatus
            ];

           

            $sql_update = "UPDATE matches SET " . implode(", ", $sql_update_parts) . " WHERE match_id = ?";
            $bind_types_update .= "i"; // Type for match_id
            $bind_params_update[] = $matchId; // Value for match_id

            $stmt_update = $conn->prepare($sql_update);

            if ($stmt_update) {
                // Use call_user_func_array for binding parameters dynamically
                // The first parameter of bind_param must be the type string, then the values.
                // array_merge([$bind_types_update], $bind_params_update) creates an array
                // where the first element is the string of types, and the rest are the actual parameters.
                // We need to pass by reference for bind_param, so use &$v for values.
                $refs = [];
                foreach ($bind_params_update as $key => $value) {
                    $refs[$key] = &$bind_params_update[$key];
                }
                call_user_func_array([$stmt_update, 'bind_param'], array_merge([$bind_types_update], $refs));

                if ($stmt_update->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Match updated successfully!";
                } else {
                    $response['error'] = "Error updating match: " . $stmt_update->error;
                }
                $stmt_update->close();
            } else {
                $response['error'] = "Database prepare error (update match): " . $conn->error;
            }
        }
    }
    echo json_encode($response);
    if (isset($close_conn_at_end) && $close_conn_at_end) { $conn->close(); }
    exit();
}

// --- Handle Delete Match Form Submission (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_match') {
    $matchId = $_POST['match_id'] ?? null;

    if (empty($matchId)) {
        $response['error'] = "Match ID is required for deletion.";
    } else {
        $sql_delete = "DELETE FROM matches WHERE match_id = ?";
        $stmt_delete = $conn->prepare($sql_delete);

        if ($stmt_delete) {
            $stmt_delete->bind_param("i", $matchId);
            if ($stmt_delete->execute()) {
                if ($stmt_delete->affected_rows > 0) {
                    $response['success'] = true;
                    $response['message'] = "Match deleted successfully!";
                } else {
                    $response['error'] = "No match found with the provided ID or nothing to delete.";
                }
            } else {
                $response['error'] = "Error deleting match: " . $stmt_delete->error;
            }
            $stmt_delete->close();
        } else {
            $response['error'] = "Database prepare error (delete match): " . $conn->error;
        }
    }
    echo json_encode($response);
    if (isset($close_conn_at_end) && $close_conn_at_end) { $conn->close(); }
    exit();
}


// --- Match Data Fetching Logic for the list (This part is for the initial display when loaded via AJAX or for search) ---
// This part will only execute if it's not an AJAX POST action handled above.
$matches = [];
$search_query = $_GET['search'] ?? '';

$sql_fetch = "
    SELECT
        m.match_id,
        m.event_id, -- Added for edit modal
        m.sport_category,
        e.event_name, /* <--- NEW: Select event name */
        t1.team_id AS team1_id, -- Added for edit modal
        t1.team_name AS team1_name,
        t1.college AS team1_college,
        t2.team_id AS team2_id, -- Added for edit modal
        t2.team_name AS team2_name,
        t2.college AS team2_college,
        m.score1,
        m.score2,
        m.match_date,
        m.match_time,
        m.venue,
        m.status,
        e.event_name,
        tw.team_id AS winner_team_id, -- Added for edit modal
        tw.team_name AS winner_name,
        m.time_finished
    FROM
        matches m
    JOIN
        teams t1 ON m.team1_id = t1.team_id
    JOIN
        teams t2 ON m.team2_id = t2.team_id
    JOIN
        events e ON m.event_id = e.event_id
    LEFT JOIN
        teams tw ON m.winner_team_id = tw.team_id
";

$where_clauses = [];
$bind_params = [];
$bind_types = '';

if (!empty($search_query)) {
    $search_param = '%' . $search_query . '%';
    $where_clauses[] = "(m.sport_category LIKE ? OR t1.team_name LIKE ? OR t2.team_name LIKE ? OR m.venue LIKE ? OR m.status LIKE ? OR e.event_name LIKE ?)";
    $bind_params = array_merge($bind_params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    $bind_types .= "ssssss";
}

if (!empty($where_clauses)) {
    $sql_fetch .= " WHERE " . implode(" AND ", $where_clauses);
}


$sql_fetch .= " ORDER BY m.match_date ASC, m.match_time ASC";

$stmt_fetch = $conn->prepare($sql_fetch);

if (!empty($bind_params)) {
    // Need to pass by reference for bind_param
    $refs = [];
    foreach ($bind_params as $key => $value) {
        $refs[$key] = &$bind_params[$key];
    }
    call_user_func_array([$stmt_fetch, 'bind_param'], array_merge([$bind_types], $refs));
}

$stmt_fetch->execute();
$result_fetch = $stmt_fetch->get_result();

if ($result_fetch) {
    while ($row = $result_fetch->fetch_assoc()) {
        $matches[] = $row;
    }
}
$stmt_fetch->close();



if (isset($close_conn_at_end) && $close_conn_at_end) {
    $conn->close();
}

/**
 * Helper function to determine badge class based on the STORED status.
 * @param string $status The stored status string (e.g., 'Upcoming', 'Completed', 'Cancelled')
 * @return string The Bootstrap badge class
 */
function getStatusBadgeClass($status) {
    switch (strtolower($status)) {
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
        default:
            return 'bg-primary';
    }
}

?>

<style>
    /* Styles specific to this content */
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
    .table td {
        vertical-align: middle;
    }
    .action-buttons {
        display: flex;
        gap: 5px;
        justify-content: center;
        align-items: center;
        flex-wrap: nowrap; /* --- CHANGED --- */ /* Force horizontal */
    }
    .action-buttons .btn {
        flex-shrink: 0;
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
    .status-badge {
        font-size: 0.9em;
        padding: 0.4em 0.8em;
        border-radius: 0.25rem;
    }
    .form-control:focus, .form-select:focus {
        box-shadow: none;
        border-color: #86b7fe;
    }
    .btn-primary, .btn-secondary, .btn-success {
        border-radius: 8px;
        padding: 10px 20px;
    }
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
    #matchesTable {
        table-layout: fixed; /* --- CHANGED --- */ /* Re-added this for fixed headers */
        width: 100%;
    }
    #matchesTable td {
        vertical-align: top;
        padding: 8px;
    }

    /* --- MODIFIED CSS --- */
    /* Adjusted column widths for better display (6 columns) */
    #matchesTable th:nth-child(1), #matchesTable td:nth-child(1) { width: 7%; }  /* Match No. */
    #matchesTable th:nth-child(2), #matchesTable td:nth-child(2) { width: 13%; } /* Event */
    #matchesTable th:nth-child(3), #matchesTable td:nth-child(3) { width: 38%; } /* Teams */
    #matchesTable th:nth-child(4), #matchesTable td:nth-child(4) { width: 15%; } /* Venue */
    #matchesTable th:nth-child(5), #matchesTable td:nth-child(5) { width: 12%; } /* Status */
    /* --- CHANGED --- : Use a fixed width for actions to guarantee buttons fit */
    #matchesTable th:nth-child(6), #matchesTable td:nth-child(6) { width: 120px; }  /* Actions */

    /* This was child 5 (Teams), now it's child 3 */
    #matchesTable td:nth-child(3) {
        white-space: normal;
        word-wrap: break-word;
        line-height: 1.3;
    }
    /* This was child 7 (Status), now it's child 5 */
    #matchesTable td:nth-child(5) {
        white-space: normal;
        min-width: 80px;
    }
    /* This was child 8 (Actions), now it's child 6 */
    #matchesTable td:nth-child(6) {
        text-align: center;
        /* --- CHANGED --- : Add vertical-align for consistency */
        vertical-align: middle;
    }
    /* --- END MODIFIED CSS --- */

    .status-badge {
        display: block;
        white-space: normal;
        margin: 0 auto;
        width: 80%;
    }
    /* New styles for improved UX */
    .modal-header .btn-close-white {
        filter: invert(1);
    }
    .nav-tabs .nav-link.active {
        font-weight: bold;
        color: #0d6efd; /* Bootstrap primary blue */
        border-bottom: 2px solid #0d6efd;
        background-color: #f8f9fa;
    }
    .results-section h6 {
        padding-bottom: 8px;
        border-bottom: 1px solid #eee;
    }
</style>

<div class="content-section">
    <h3 class="fw-bold mb-4">Match Schedule</h3>

    <div class="d-flex justify-content-end mb-4">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMatchModal">
            <i class="fas fa-plus-circle me-2"></i>Add New Match
        </button>
    </div>

    <div class="mb-3">
        <input type="text" id="matchSearch" class="form-control" placeholder="Search matches by sport, team, venue, or status...">
    </div>

    <div>
        <table class="table table-bordered table-hover shadow-sm" id="matchesTable">
            <colgroup>
                 <col style="width: 7%;">
                <col style="width: 13%;">
                <col style="width: 38%;">
                <col style="width: 15%;">
                <col style="width: 12%;">
                <col style="width: 120px;"> </colgroup>
            <thead>
                <tr>
                    <th>Match No.</th>
                    <th>Event</th>
                    <th>Teams</th>
                    <th>Venue</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($matches)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No matches scheduled yet. Add a new match to get started!</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($matches as $index => $match):
                        $status_badge_class = getStatusBadgeClass($match['status']);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($index + 1) ?></td>
                            <td><?= htmlspecialchars($match['event_name'] ?? 'N/A') ?></td>
                            <td>
                                <strong><?= htmlspecialchars($match['team1_name']) ?></strong> (<?= htmlspecialchars($match['team1_college'] ?? 'N/A') ?>)
                                <br>vs<br>
                                <strong><?= htmlspecialchars($match['team2_name']) ?></strong> (<?= htmlspecialchars($match['team2_college'] ?? 'N/A') ?>)
                            </td>
                            <td><?= htmlspecialchars($match['venue']) ?></td>
                            <td>
                                <span class="badge status-badge <?= $status_badge_class ?>">
                                    <?= htmlspecialchars($match['status']) ?>
                                </span>
                            </td>
                            <td class="action-buttons">
                                <button type="button" class="btn btn-sm btn-info text-white view-match-btn"
                                        data-id="<?= htmlspecialchars($match['match_id']) ?>" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-warning edit-match-btn"
                                        data-bs-toggle="modal" data-bs-target="#editMatchModal"
                                        data-id="<?= htmlspecialchars($match['match_id']) ?>"
                                        data-event-id="<?= htmlspecialchars($match['event_id']) ?>"
                                        data-sport-category="<?= htmlspecialchars($match['sport_category']) ?>"
                                        data-team1-id="<?= htmlspecialchars($match['team1_id']) ?>"
                                        data-team2-id="<?= htmlspecialchars($match['team2_id']) ?>"
                                        data-match-date="<?= htmlspecialchars($match['match_date']) ?>"
                                        data-match-time="<?= htmlspecialchars($match['match_time']) ?>"
                                        data-venue="<?= htmlspecialchars($match['venue']) ?>"
                                        data-status="<?= htmlspecialchars($match['status']) ?>"
                                        data-score1="<?= htmlspecialchars($match['score1'] ?? '') ?>"
                                        data-score2="<?= htmlspecialchars($match['score2'] ?? '') ?>"
                                        data-winner-team-id="<?= htmlspecialchars($match['winner_team_id'] ?? '') ?>"
                                        data-time-finished="<?= htmlspecialchars($match['time_finished'] ? date('Y-m-d\TH:i', strtotime($match['time_finished'])) : '') ?>"
                                        title="Edit Match">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger delete-match-btn" data-id="<?= htmlspecialchars($match['match_id']) ?>" title="Delete Match"><i class="fas fa-trash-alt"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="content-section mt-5"> 
    <h3 class="fw-bold mb-4">Results Management</h3>

    <div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Completed Matches Table</h5>
    </div>
    <div class="card-body p-0">
        <div>
            <table class="table table-bordered table-hover mb-0">
                <colgroup>
                    <col style="width: 20%;">
                    <col style="width: 15%;">
                    <col style="width: 10%;">
                    <col style="width: 15%;">
                    <col style="width: 10%;">
                    <col style="width: 15%;">
                    <col style="width: 120px;"> </colgroup>
                <thead class="bg-light">
                    <tr>
                        <th scope="col">Match</th>
                        <th scope="col">Event</th> 
                        <th scope="col">Scores</th>
                        <th scope="col">Winner</th>
                        <th scope="col">Status</th>
                        <th scope="col">Time Finished</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($matches)): ?>
                        <tr>
                            <td colspan="7" class="text-center">No matches found for results management.</td> 
                        </tr>
                    <?php else: ?>
                        <?php foreach ($matches as $match): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($match['team1_name'] . ' vs ' . $match['team2_name']); ?></td>
                                
                                <td><?php echo htmlspecialchars($match['event_name']); ?></td>
                                
                                <td>
                                    <?php 
                                        $score1 = $match['score1'] ?? '-';
                                        $score2 = $match['score2'] ?? '-';
                                        $display_score = htmlspecialchars("{$score1} - {$score2}");

                                        // Scores column is already bold if status is Completed (using <strong>)
                                        if ($match['status'] === 'Completed' && $score1 !== '-' && $score2 !== '-') {
                                            echo "<strong>{$display_score}</strong>";
                                        } else {
                                            echo $display_score;
                                        }
                                    ?>
                                </td>
                                
                                <td class="fw-bold"><?php echo htmlspecialchars($match['winner_name'] ?? 'N/A'); ?></td>
                                
                                <td><span class="badge status-badge <?php echo getStatusBadgeClass($match['status']); ?>"><?php echo htmlspecialchars($match['status']); ?></span></td>
                                
                                <td>
                                    <?php 
                                        if ($match['time_finished']) {
                                            // Format the time finished for display
                                            echo (new DateTime($match['time_finished']))->format('M j, Y H:i');
                                        } else {
                                            echo 'N/A';
                                        }
                                    ?>
                                </td>

                                <td class="action-buttons">
                                    <button type="button" class="btn btn-sm btn-success add-result-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#addResultModal"
                                        data-event-name="<?= htmlspecialchars($match['event_name']) ?>"
                                        data-match-id="<?= htmlspecialchars($match['match_id']) ?>"
                                        data-team1-id="<?= htmlspecialchars($match['team1_id']) ?>"
                                        data-team2-id="<?= htmlspecialchars($match['team2_id']) ?>"
                                        data-team1-college="<?= htmlspecialchars($match['team1_college']) ?>"
                                        data-team2-college="<?= htmlspecialchars($match['team2_college']) ?>"
                                        data-score1="<?= htmlspecialchars($match['score1']) ?>"
                                        data-score2="<?= htmlspecialchars($match['score2']) ?>"
                                        data-winner-team-id="<?= htmlspecialchars($match['winner_team_id']) ?>"
                                        data-time-finished="<?= htmlspecialchars($match['time_finished']) ?>"
                                        data-status="<?= htmlspecialchars($match['status']) ?>"
                                    >
                                        <i class="fas fa-chart-line me-1"></i> Result
                                    </button>

                                    <button type="button" class="btn btn-sm btn-danger delete-match-btn" 
                                            data-id="<?= htmlspecialchars($match['match_id']) ?>" 
                                            title="Delete Match">
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


    <div class="modal fade" id="addResultModal" tabindex="-1" aria-labelledby="addResultModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="addResultModalLabel">Add/Edit Match Result</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addResultForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_result">
                    <input type="hidden" name="match_id" id="resultMatchId">
                    
                    <div class="mb-3">
                        <label for="resultEventName" class="form-label">Event</label>
                        <input type="text" class="form-control" id="resultEventName" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="resultMatchDisplay" class="form-label fw-bold">Match:</label>
                        <p id="resultMatchDisplay" class="form-control-plaintext"></p>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="resultScore1" class="form-label" id="labelScore1">Score (Team 1):</label>
                            <input type="number" class="form-control" id="resultScore1" name="score1" required min="0">
                        </div>
                        <div class="col-md-6">
                            <label for="resultScore2" class="form-label" id="labelScore2">Score (Team 2):</label>
                            <input type="number" class="form-control" id="resultScore2" name="score2" required min="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="resultWinnerTeamId" class="form-label">Winning Team:</label>
                        <select class="form-select" id="resultWinnerTeamId" name="winner_team_id" required>
                            <option value="">Select Winner</option>
                            </select>
                    </div>

                    <div class="mb-3">
                        <label for="resultTimeFinished" class="form-label">Time Finished:</label>
                        <input type="datetime-local" class="form-control" id="resultTimeFinished" name="time_finished" required>
                    </div>

                    <div class="mb-3">
                        <label for="resultMatchStatus" class="form-label">Current Status</label>
                        <input type="text" class="form-control" id="resultMatchStatus" readonly>
                    </div>
                    
                    <div id="resultMessage" class="alert d-none mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Save Result</button>
                </div>
            </form>
        </div>
    </div>
</div>


<div class="modal fade" id="addMatchModal" tabindex="-1" aria-labelledby="addMatchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addMatchModalLabel"><i class="fas fa-plus-circle me-2"></i>Schedule New Match</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addMatchForm" action="manage_matches_schedule.php" method="POST">
                <input type="hidden" name="action" value="add_match">
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-6 border-end">
                            <h6 class="mb-3 text-primary"><i class="fas fa-users me-1"></i>Match Details</h6>
                            <div class="mb-3">
                                <label for="addEventIdMatch" class="form-label">Event<span class="text-danger">*</span></label>
                                <select class="form-select" id="addEventIdMatch" name="event_id" required>
                                    <option value="" disabled selected>Select an Event</option>
                                    <?php foreach ($all_events as $event): ?>
                                        <option value="<?= htmlspecialchars($event['event_id']) ?>"><?= htmlspecialchars($event['event_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="addSportCategory" class="form-label">Sport Category<span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="addSportCategory" name="sport_category" placeholder="e.g., Basketball, Volleyball" required>
                            </div>
                            <div class="mb-3">
                                <label for="addTeam1Id" class="form-label">Team 1<span class="text-danger">*</span></label>
                                <select class="form-select" id="addTeam1Id" name="team1_id" required>
                                    <option value="" disabled selected>Select Team 1</option>
                                    <?php foreach ($all_teams as $team): ?>
                                        <option value="<?= htmlspecialchars($team['team_id']) ?>"><?= htmlspecialchars($team['team_name']) ?> (<?= htmlspecialchars($team['college'] ?? 'N/A') ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="addTeam2Id" class="form-label">Team 2<span class="text-danger">*</span></label>
                                <select class="form-select" id="addTeam2Id" name="team2_id" required>
                                    <option value="" disabled selected>Select Team 2</option>
                                    <?php foreach ($all_teams as $team): ?>
                                        <option value="<?= htmlspecialchars($team['team_id']) ?>"><?= htmlspecialchars($team['team_name']) ?> (<?= htmlspecialchars($team['college'] ?? 'N/A') ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <h6 class="mb-3 text-primary"><i class="fas fa-clock me-1"></i>Schedule & Location</h6>
                            <div class="mb-3">
                                <label for="addMatchDate" class="form-label">Date<span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="addMatchDate" name="match_date" required>
                            </div>
                            <div class="mb-3">
                                <label for="addMatchTime" class="form-label">Time<span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="addMatchTime" name="match_time" required>
                            </div>
                            <div class="mb-3">
                                <label for="addVenue" class="form-label">Venue<span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="addVenue" name="venue" placeholder="e.g., College Gym, Field A" required>
                            </div>
                            <div class="mb-3">
                                <label for="addStatus" class="form-label">Status<span class="text-danger">*</span></label>
                                <select class="form-select" id="addStatus" name="status" required>
                                    <?php foreach ($match_statuses as $statusOption): ?>
                                        <option value="<?= htmlspecialchars($statusOption) ?>" <?= ($statusOption === 'Upcoming') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($statusOption) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Schedule Match</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editMatchModal" tabindex="-1" aria-labelledby="editMatchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="editMatchModalLabel"><i class="fas fa-edit me-2"></i>Edit Match & Results</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editMatchForm" action="manage_matches_schedule.php" method="POST">
                <input type="hidden" name="action" value="update_match">
                <input type="hidden" id="editMatchId" name="match_id">
                
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-4" id="editMatchTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="schedule-tab" data-bs-toggle="tab" data-bs-target="#schedule-pane" type="button" role="tab" aria-controls="schedule-pane" aria-selected="true">
            <i class="fas fa-calendar-alt me-1"></i>Schedule Details
        </button>
    </li>
</ul>

<div class="tab-content" id="editMatchTabsContent">
    
    <div class="tab-pane fade show active" id="schedule-pane" role="tabpanel" aria-labelledby="schedule-tab">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="editEventIdMatch" class="form-label">Event<span class="text-danger">*</span></label>
                    <select class="form-select" id="editEventIdMatch" name="event_id" required>
                        <option value="">Select an event</option>
                        <?php foreach ($all_events as $event): ?>
                            <option value="<?= htmlspecialchars($event['event_id']) ?>"><?= htmlspecialchars($event['event_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="editSportCategory" class="form-label">Sport Category<span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editSportCategory" name="sport_category" required>
                </div>
                <div class="mb-3">
                    <label for="editTeam1Id" class="form-label">Team 1<span class="text-danger">*</span></label>
                    <select class="form-select" id="editTeam1Id" name="team1_id" required>
                        <option value="">Select Team 1</option>
                        <?php foreach ($all_teams as $team): ?>
                            <option value="<?= htmlspecialchars($team['team_id']) ?>"><?= htmlspecialchars($team['team_name']) ?> (<?= htmlspecialchars($team['college'] ?? 'N/A') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="editTeam2Id" class="form-label">Team 2<span class="text-danger">*</span></label>
                    <select class="form-select" id="editTeam2Id" name="team2_id" required>
                        <option value="">Select Team 2</option>
                        <?php foreach ($all_teams as $team): ?>
                            <option value="<?= htmlspecialchars($team['team_id']) ?>"><?= htmlspecialchars($team['team_name']) ?> (<?= htmlspecialchars($team['college'] ?? 'N/A') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="editMatchDate" class="form-label">Date<span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="editMatchDate" name="match_date" required>
                </div>
                <div class="mb-3">
                    <label for="editMatchTime" class="form-label">Time<span class="text-danger">*</span></label>
                    <input type="time" class="form-control" id="editMatchTime" name="match_time" required>
                </div>
                <div class="mb-3">
                    <label for="editVenue" class="form-label">Venue<span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editVenue" name="venue" required>
                </div>
                <div class="mb-3">
                    <label for="editMatchStatus" class="form-label">Status<span class="text-danger">*</span></label>
                    <select class="form-select" id="editMatchStatus" name="status" required>
                        <?php foreach ($match_statuses as $statusOption): ?>
                            <option value="<?= htmlspecialchars($statusOption) ?>"><?= htmlspecialchars($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

       
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewMatchModal" tabindex="-1" aria-labelledby="viewMatchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewMatchModalLabel"><i class="fas fa-info-circle me-2"></i>Match Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="view-detail-item">
                    <span class="view-detail-label">Match ID:</span>
                    <span class="view-detail-value" id="viewMatchId"></span>
                </div>
               <div class="view-detail-item">
                    <span class="view-detail-label">Event:</span> 
                    <span class="view-detail-value" id="viewEventName"></span> 
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Sport:</span>
                    <span class="view-detail-value" id="viewSportCategory"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Teams:</span>
                    <span class="view-detail-value" id="viewTeams"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Date:</span>
                    <span class="view-detail-value" id="viewMatchDate"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Time:</span>
                    <span class="view-detail-value" id="viewMatchTime"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Venue:</span>
                    <span class="view-detail-value" id="viewVenue"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Status:</span>
                    <span class="view-detail-value" id="viewMatchStatus"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>