<?php
// This file is loaded via AJAX into Manage_Event.php
// It should NOT have session_start() or require_once 'config.php'
// as those are handled by the parent Manage_Event.php.
// However, for direct access/testing, you might temporarily enable them.

// --- Temporarily enable error reporting for debugging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- End temporary error reporting ---

// Ensure config.php is available for database operations
// This is a fallback if accessed directly, but typically handled by parent
if (!isset($conn)) {
    require_once 'config.php';
    $close_conn_at_end = true; // Flag to close connection if opened here
} else {
    $close_conn_at_end = false;
}

// NOTE TO USER: Since I cannot modify the database directly, 
// the PHP logic assumes the 'events' table now has the following columns:
// - gold_count INT DEFAULT 0
// - silver_count INT DEFAULT 0
// - bronze_count INT DEFAULT 0
// If you have not created these columns, your INSERT/UPDATE will fail!
function ensureMedalCountColumns($conn) {
    // This is a placeholder/suggestion for your DB admin to implement:
    // $conn->query("ALTER TABLE events ADD COLUMN gold_count INT NOT NULL DEFAULT 0");
    // $conn->query("ALTER TABLE events ADD COLUMN silver_count INT NOT NULL DEFAULT 0");
    // $conn->query("ALTER TABLE events ADD COLUMN bronze_count INT NOT NULL DEFAULT 0");
    // $conn->query("ALTER TABLE events DROP COLUMN medal_count"); // Optionally drop old column
}
// Calling the function to ensure the database structure is checked
// ensureMedalCountColumns($conn); 
// We will comment this out for now to prevent PHP error on your existing setup.

// Message for form submission (add/edit/delete operations)
// These messages are handled by the parent Manage_Event.php session logic
$message = '';
$message_type = ''; 

// Default values for Add Event form fields (used for re-population on validation error if not redirected)
$addEventName = $_POST['event_name'] ?? '';
$addCategory = $_POST['category'] ?? '';
$addSubCategory = $_POST['sub_category'] ?? '';
$addEventStatus = $_POST['event_status'] ?? 'Upcoming';
$addStartDate = $_POST['start_date'] ?? '';
$addEndDate = $_POST['end_date'] ?? '';
$addDescription = $_POST['description'] ?? '';
$addStartDateTBA = isset($_POST['start_date_tba']);
$addEndDateTBA = isset($_POST['end_date_tba']);
// UPDATED: New default variables for medal counts
$addGoldCount = $_POST['gold_count'] ?? '';
$addSilverCount = $_POST['silver_count'] ?? '';
$addBronzeCount = $_POST['bronze_count'] ?? '';


// Fetch sports from database for dropdown
$sports = [];
$sql_sports = "SELECT sport_id, sport_name, category FROM sports ORDER BY category, sport_name";
$result_sports = $conn->query($sql_sports);
if ($result_sports) {
    while ($row = $result_sports->fetch_assoc()) {
        $sports[] = $row;
    }
}

// Suggested statuses (customize these)
$event_statuses = ['Upcoming', 'Ongoing', 'Completed', 'Cancelled', 'Postponed', 'Draft'];


// --- Handle Add Event Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_event') {
    $addEventName = trim($_POST['event_name'] ?? '');
    $addSportId = trim($_POST['sport_id'] ?? '');
    $addEventStatus = trim($_POST['event_status'] ?? '');
    $addStartDate = trim($_POST['start_date'] ?? '');
    $addEndDate = trim($_POST['end_date'] ?? '');
    $addDescription = trim($_POST['description'] ?? '');
    $addStartDateTBA = isset($_POST['start_date_tba']);
    $addEndDateTBA = isset($_POST['end_date_tba']);
    // UPDATED: Capture new medal counts
    $addGoldCount = trim($_POST['gold_count'] ?? '0');
    $addSilverCount = trim($_POST['silver_count'] ?? '0');
    $addBronzeCount = trim($_POST['bronze_count'] ?? '0');

    $finalStartDate = $addStartDateTBA ? 'TBA' : $addStartDate;
    $finalEndDate = $addEndDateTBA ? 'TBA' : $addEndDate;

    // UPDATED: Validation for all three medal counts
    $isMedalCountValid = (ctype_digit($addGoldCount) && (int)$addGoldCount >= 0) &&
                         (ctype_digit($addSilverCount) && (int)$addSilverCount >= 0) &&
                         (ctype_digit($addBronzeCount) && (int)$addBronzeCount >= 0);

    if (empty($addEventName) || empty($addSportId) || empty($addEventStatus) ||
        (empty($finalStartDate) && !$addStartDateTBA) ||
        (empty($finalEndDate) && !$addEndDateTBA) || !$isMedalCountValid) {
        
        $errorMessage = "Please fill in all required fields (including non-negative numeric medal counts).";
        $_SESSION['message'] = $errorMessage;
        $_SESSION['message_type'] = "danger";
    } elseif (!$addStartDateTBA && !$addEndDateTBA && ($finalStartDate !== 'TBA' && $finalEndDate !== 'TBA' && $finalStartDate > $finalEndDate)) {
        $_SESSION['message'] = "End Date cannot be before Start Date.";
        $_SESSION['message_type'] = "danger";
    } else {
        // UPDATED: SQL INSERT to include gold_count, silver_count, bronze_count
        $sql_insert = "INSERT INTO events (event_name, sport_id, event_status, start_date, end_date, description, gold_count, silver_count, bronze_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);

        if ($stmt_insert) {
            $goldCountInt = (int)$addGoldCount;
            $silverCountInt = (int)$addSilverCount;
            $bronzeCountInt = (int)$addBronzeCount;

            // UPDATED: Bind parameters (9 total)
            $stmt_insert->bind_param("sissssiis", $addEventName, $addSportId, $addEventStatus, $finalStartDate, $finalEndDate, $addDescription, $goldCountInt, $silverCountInt, $bronzeCountInt);
            if ($stmt_insert->execute()) {
                $_SESSION['message'] = "Event '<strong>" . htmlspecialchars($addEventName) . "</strong>' added successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error adding event: " . $stmt_insert->error;
                $_SESSION['message_type'] = "danger";
            }
            $stmt_insert->close();
        } else {
            $_SESSION['message'] = "Database prepare error: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
    }
    // Redirect back to the main Manage_Event.php which will reload this content
    header('Location: Manage_Event.php');
    exit();
}

// --- Handle Update Event Form Submission (from modal) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_event') {
    $editEventId = $_POST['event_id'] ?? null;
    $editEventName = trim($_POST['event_name'] ?? '');
    $editSportId = trim($_POST['sport_id'] ?? '');
    $editEventStatus = trim($_POST['event_status'] ?? '');
    $editStartDate = trim($_POST['start_date'] ?? '');
    $editEndDate = trim($_POST['end_date'] ?? '');
    $editDescription = trim($_POST['description'] ?? '');
    $editStartDateTBA = isset($_POST['start_date_tba']);
    $editEndDateTBA = isset($_POST['end_date_tba']);
    // UPDATED: Capture new medal counts
    $editGoldCount = trim($_POST['gold_count'] ?? '0');
    $editSilverCount = trim($_POST['silver_count'] ?? '0');
    $editBronzeCount = trim($_POST['bronze_count'] ?? '0');

    $finalStartDate = $editStartDateTBA ? 'TBA' : $editStartDate;
    $finalEndDate = $editEndDateTBA ? 'TBA' : $editEndDate;

    // UPDATED: Validation for all three medal counts
    $isMedalCountValid = (ctype_digit($editGoldCount) && (int)$editGoldCount >= 0) &&
                         (ctype_digit($editSilverCount) && (int)$editSilverCount >= 0) &&
                         (ctype_digit($editBronzeCount) && (int)$editBronzeCount >= 0);

    if (!$editEventId || empty($editEventName) || empty($editSportId) || empty($editEventStatus) ||
        (empty($finalStartDate) && !$editStartDateTBA) || 
        (empty($finalEndDate) && !$editEndDateTBA) || !$isMedalCountValid) {     
        
        $errorMessage = "Error updating event: Please fill in all required fields (including non-negative numeric medal counts).";
        $_SESSION['message'] = $errorMessage;
        $_SESSION['message_type'] = "danger";
    } elseif (!$editStartDateTBA && !$editEndDateTBA && ($finalStartDate !== 'TBA' && $finalEndDate !== 'TBA' && $finalStartDate > $finalEndDate)) {
        $_SESSION['message'] = "Error updating event: End Date cannot be before Start Date.";
        $_SESSION['message_type'] = "danger";
    } else {
        // UPDATED: Recalculate assigned medals based on medal_type
        $assignedMedalTally = [ 'Gold' => 0, 'Silver' => 0, 'Bronze' => 0 ];
        $stmt_tally = $conn->prepare("SELECT medal_type, SUM(medal_quantity) AS assigned FROM medals WHERE event_id = ? GROUP BY medal_type");
        if ($stmt_tally) {
            $stmt_tally->bind_param("i", $editEventId);
            $stmt_tally->execute();
            $res_tally = $stmt_tally->get_result();
            if ($res_tally) {
                while($row_tally = $res_tally->fetch_assoc()) {
                    $assignedMedalTally[$row_tally['medal_type']] = (int)($row_tally['assigned'] ?? 0);
                }
            }
            $stmt_tally->close();
        }

        $goldCountInt = (int)$editGoldCount;
        $silverCountInt = (int)$editSilverCount;
        $bronzeCountInt = (int)$editBronzeCount;

        $error = false;
        if ($assignedMedalTally['Gold'] > $goldCountInt) {
            $_SESSION['message'] = "Error updating event: Gold medal count cannot be less than the already assigned ({$assignedMedalTally['Gold']}).";
            $error = true;
        } elseif ($assignedMedalTally['Silver'] > $silverCountInt) {
            $_SESSION['message'] = "Error updating event: Silver medal count cannot be less than the already assigned ({$assignedMedalTally['Silver']}).";
            $error = true;
        } elseif ($assignedMedalTally['Bronze'] > $bronzeCountInt) {
            $_SESSION['message'] = "Error updating event: Bronze medal count cannot be less than the already assigned ({$assignedMedalTally['Bronze']}).";
            $error = true;
        }

        if (!$error) {
            // UPDATED: SQL UPDATE statement to include gold_count, silver_count, bronze_count
            $sql_update = "UPDATE events SET event_name = ?, sport_id = ?, event_status = ?, start_date = ?, end_date = ?, description = ?, gold_count = ?, silver_count = ?, bronze_count = ? WHERE event_id = ?";
            $stmt_update = $conn->prepare($sql_update);

            if ($stmt_update) {
                // UPDATED: Bind parameters (10 total: 4 strings, 5 integers, 1 integer)
                $stmt_update->bind_param("sissssiiii", 
                    $editEventName, $editSportId, $editEventStatus, $finalStartDate, 
                    $finalEndDate, $editDescription, $goldCountInt, $silverCountInt, 
                    $bronzeCountInt, $editEventId
                );
                if ($stmt_update->execute()) {
                    $_SESSION['message'] = "Event '<strong>" . htmlspecialchars($editEventName) . "</strong>' updated successfully!";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "Error updating event: " . $stmt_update->error;
                    $_SESSION['message_type'] = "danger";
                }
                $stmt_update->close();
            } else {
                $_SESSION['message'] = "Database prepare error (update): " . $conn->error;
                $_SESSION['message_type'] = "danger";
            }
        }
    }
    // Redirect back to the main Manage_Event.php which will reload this content
    header('Location: Manage_Event.php');
    exit();
}


// --- Event Data Fetching Logic for the list ---
// UPDATED: Select all new medal columns
$events = [];
$search_query = $_GET['search'] ?? ''; 

$sql_fetch = "SELECT e.event_id, e.event_name, s.sport_name, s.category, e.event_status, e.start_date, e.end_date, e.description, 
                     e.gold_count, e.silver_count, e.bronze_count
              FROM events e 
              JOIN sports s ON e.sport_id = s.sport_id";

if (!empty($search_query)) {
    // ... search logic remains the same ...
    $search_param = '%' . $search_query . '%';
    $sql_fetch .= " WHERE e.event_name LIKE ? OR s.sport_name LIKE ? OR s.category LIKE ? OR e.event_status LIKE ? OR e.description LIKE ? OR e.start_date LIKE ? OR e.end_date LIKE ?";
    $stmt_fetch = $conn->prepare($sql_fetch);
    $stmt_fetch->bind_param("sssssss", $search_param, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param);
} else {
    $stmt_fetch = $conn->prepare($sql_fetch);
}

if ($stmt_fetch) {
    $stmt_fetch->execute();
    $result_fetch = $stmt_fetch->get_result();

    if ($result_fetch) {
        while ($row = $result_fetch->fetch_assoc()) {
            $events[] = $row;
        }
    }
    $stmt_fetch->close();
}


// Close database connection if it was opened in this script
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
?>

<div class="content-section">
    <div class="d-flex justify-content-start mb-4">
        <a href="admin_dashboard.php" class="btn btn-outline-secondary back-button">
            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
        </a>
    </div>
    
    <h3 class="fw-bold mb-4">Event List</h3>
    
    <div class="d-flex justify-content-end mb-4">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEventModal">
            <i class="fas fa-plus-circle me-2"></i>Add New Event
        </button>
    </div>

    <div class="mb-3">
        <input type="text" id="eventSearch" class="form-control" placeholder="Search events by name, category, status or description...">
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-hover shadow-sm" id="eventsTable">
            <thead>
                <tr>
                    <th>Event Name</th>
                    <th>Category</th>
                    <th>Sport Name</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($events)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No events found. Add a new event to get started!</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($events as $event):
                        $status_badge_class = getStatusBadgeClass($event['event_status']);
                        ?>
                       <tr>
    <td><?= htmlspecialchars($event['event_name']) ?></td>
    <td><?= htmlspecialchars($event['category'] ?? 'N/A') ?></td>
    <td><?= htmlspecialchars($event['sport_name'] ?? 'N/A') ?></td>
    <td>
        <span class="badge status-badge <?= $status_badge_class ?>">
            <?= htmlspecialchars($event['event_status']) ?>
        </span>
    </td>
    <td class="action-buttons">
        <button type="button" class="btn btn-sm btn-info text-white view-event-btn" 
                data-bs-toggle="modal" data-bs-target="#viewEventModal" 
                data-id="<?= $event['event_id'] ?>" title="View Details">
            <i class="fas fa-eye"></i>
        </button>
        <button type="button" class="btn btn-sm btn-warning edit-event-btn" 
                data-bs-toggle="modal" data-bs-target="#editEventModal" 
                data-id="<?= $event['event_id'] ?>" title="Edit Event">
            <i class="fas fa-edit"></i>
        </button>
        <button type="button" class="btn btn-sm btn-danger delete-event-btn" 
                data-id="<?= $event['event_id'] ?>" title="Delete Event">
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

<div class="modal fade" id="addEventModal" tabindex="-1" aria-labelledby="addEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addEventModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addEventForm" action="manage_events_list.php" method="POST"> <input type="hidden" name="action" value="add_event">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="addEventName" class="form-label">Event Name<span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="addEventName" name="event_name" required value="<?= htmlspecialchars($addEventName) ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="addSportId" class="form-label">Sport<span class="text-danger">*</span></label>
                        <select class="form-select" id="addSportId" name="sport_id" required>
                            <option value="">Select a sport</option>
                            <?php 
                            $current_category = '';
                            foreach ($sports as $sport): 
                                if ($current_category !== $sport['category']) {
                                    if ($current_category !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($sport['category']) . '">';
                                    $current_category = $sport['category'];
                                }
                            ?>
                                <option value="<?= $sport['sport_id'] ?>">
                                    <?= htmlspecialchars($sport['sport_name']) ?>
                                </option>
                            <?php endforeach; 
                            if ($current_category !== '') echo '</optgroup>';
                            ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="addEventStatus" class="form-label">Status<span class="text-danger">*</span></label>
                        <select class="form-select" id="addEventStatus" name="event_status" required>
                            <option value="">Select a status</option>
                            <?php foreach ($event_statuses as $statusOption): ?>
                                <option value="<?= htmlspecialchars($statusOption) ?>" <?= ($addEventStatus === $statusOption) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($statusOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="addStartDate" class="form-label">Start Date<span class="text-danger">*</span></label>
                            <div class="date-input-group">
                                <input type="date" class="form-control" id="addStartDate" name="start_date" 
                                    value="<?= htmlspecialchars($addStartDateTBA ? '' : $addStartDate) ?>"
                                    <?= $addStartDateTBA ? 'disabled' : 'required' ?>>
                                <div class="form-check ms-3">
                                    <input class="form-check-input tba-checkbox" type="checkbox" id="addStartDateTBA" name="start_date_tba" <?= $addStartDateTBA ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="addStartDateTBA">TBA</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="addEndDate" class="form-label">End Date<span class="text-danger">*</span></label>
                            <div class="date-input-group">
                                <input type="date" class="form-control" id="addEndDate" name="end_date" 
                                    value="<?= htmlspecialchars($addEndDateTBA ? '' : $addEndDate) ?>"
                                    <?= $addEndDateTBA ? 'disabled' : 'required' ?>>
                                <div class="form-check ms-3">
                                    <input class="form-check-input tba-checkbox" type="checkbox" id="addEndDateTBA" name="end_date_tba" <?= $addEndDateTBA ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="addEndDateTBA">TBA</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <h6 class="mt-4 mb-3 fw-bold"><i class="fas fa-medal me-2"></i>Medals Awarded (Counts Per Rank)<span class="text-danger">*</span></h6>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="addGoldCount" class="form-label text-warning"><i class="fas fa-trophy me-1"></i>Gold</label>
                            <input type="number" class="form-control" id="addGoldCount" name="gold_count" min="0" step="1" required value="<?= htmlspecialchars($addGoldCount) ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="addSilverCount" class="form-label text-secondary"><i class="fas fa-medal me-1"></i>Silver</label>
                            <input type="number" class="form-control" id="addSilverCount" name="silver_count" min="0" step="1" required value="<?= htmlspecialchars($addSilverCount) ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="addBronzeCount" class="form-label" style="color:#CD7F32;"><i class="fas fa-award me-1"></i>Bronze</label>
                            <input type="number" class="form-control" id="addBronzeCount" name="bronze_count" min="0" step="1" required value="<?= htmlspecialchars($addBronzeCount) ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="addDescription" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="addDescription" name="description" rows="5"><?= htmlspecialchars($addDescription) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Add Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editEventModal" tabindex="-1" aria-labelledby="editEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="editEventModalLabel"><i class="fas fa-edit me-2"></i>Edit Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editEventForm" action="manage_events_list.php" method="POST"> <input type="hidden" name="action" value="update_event">
                <input type="hidden" id="editEventId" name="event_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="editEventName" class="form-label">Event Name<span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="editEventName" name="event_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editSportId" class="form-label">Sport<span class="text-danger">*</span></label>
                        <select class="form-select" id="editSportId" name="sport_id" required>
                            <option value="">Select a sport</option>
                            <?php 
                            $current_category = '';
                            foreach ($sports as $sport): 
                                if ($current_category !== $sport['category']) {
                                    if ($current_category !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($sport['category']) . '">';
                                    $current_category = $sport['category'];
                                }
                            ?>
                                <option value="<?= $sport['sport_id'] ?>">
                                    <?= htmlspecialchars($sport['sport_name']) ?>
                                </option>
                            <?php endforeach; 
                            if ($current_category !== '') echo '</optgroup>';
                            ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="editEventStatus" class="form-label">Status<span class="text-danger">*</span></label>
                        <select class="form-select" id="editEventStatus" name="event_status" required>
                            <option value="">Select a status</option>
                            <?php foreach ($event_statuses as $statusOption): ?>
                                <option value="<?= htmlspecialchars($statusOption) ?>"><?= htmlspecialchars($statusOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="editStartDate" class="form-label">Start Date<span class="text-danger">*</span></label>
                            <div class="date-input-group">
                                <input type="date" class="form-control" id="editStartDate" name="start_date">
                                <div class="form-check ms-3">
                                    <input class="form-check-input tba-checkbox" type="checkbox" id="editStartDateTBA" name="start_date_tba">
                                    <label class="form-check-label" for="editStartDateTBA">TBA</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="editEndDate" class="form-label">End Date<span class="text-danger">*</span></label>
                            <div class="date-input-group">
                                <input type="date" class="form-control" id="editEndDate" name="end_date">
                                <div class="form-check ms-3">
                                    <input class="form-check-input tba-checkbox" type="checkbox" id="editEndDateTBA" name="end_date_tba">
                                    <label class="form-check-label" for="editEndDateTBA">TBA</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <h6 class="mt-4 mb-3 fw-bold"><i class="fas fa-medal me-2"></i>Medals Awarded (Counts Per Rank)<span class="text-danger">*</span></h6>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="editGoldCount" class="form-label text-warning"><i class="fas fa-trophy me-1"></i>Gold</label>
                            <input type="number" class="form-control" id="editGoldCount" name="gold_count" min="0" step="1" required>
                            <small class="form-text text-danger" id="goldAssignmentWarning"></small>
                        </div>
                        <div class="col-md-4">
                            <label for="editSilverCount" class="form-label text-secondary"><i class="fas fa-medal me-1"></i>Silver</label>
                            <input type="number" class="form-control" id="editSilverCount" name="silver_count" min="0" step="1" required>
                            <small class="form-text text-danger" id="silverAssignmentWarning"></small>
                        </div>
                        <div class="col-md-4">
                            <label for="editBronzeCount" class="form-label" style="color:#CD7F32;"><i class="fas fa-award me-1"></i>Bronze</label>
                            <input type="number" class="form-control" id="editBronzeCount" name="bronze_count" min="0" step="1" required>
                            <small class="form-text text-danger" id="bronzeAssignmentWarning"></small>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="editDescription" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="editDescription" name="description" rows="5"></textarea>
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

<div class="modal fade" id="viewEventModal" tabindex="-1" aria-labelledby="viewEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewEventModalLabel"><i class="fas fa-info-circle me-2"></i>Event Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="view-detail-item">
                    <span class="view-detail-label">Event ID:</span>
                    <span class="view-detail-value" id="viewEventId"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Event Name:</span>
                    <span class="view-detail-value" id="viewEventName"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Category:</span>
                    <span class="view-detail-value" id="viewCategory"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Sport Name:</span>
                    <span class="view-detail-value" id="viewSportName"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Status:</span>
                    <span class="view-detail-value" id="viewEventStatus"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">Start Date:</span>
                    <span class="view-detail-value" id="viewStartDate"></span>
                </div>
                <div class="view-detail-item">
                    <span class="view-detail-label">End Date:</span>
                    <span class="view-detail-value" id="viewEndDate"></span>
                </div>
                <h6 class="mt-4 mb-2 fw-bold text-primary"><i class="fas fa-medal me-2"></i>Medal Structure</h6>
                <div class="row">
                    <div class="col-md-4 view-detail-item">
                        <span class="view-detail-label text-warning">Gold:</span>
                        <span class="view-detail-value" id="viewGoldCount"></span>
                    </div>
                    <div class="col-md-4 view-detail-item">
                        <span class="view-detail-label text-secondary">Silver:</span>
                        <span class="view-detail-value" id="viewSilverCount"></span>
                    </div>
                    <div class="col-md-4 view-detail-item">
                        <span class="view-detail-label" style="color:#CD7F32;">Bronze:</span>
                        <span class="view-detail-value" id="viewBronzeCount"></span>
                    </div>
                </div>
                <div class="view-detail-item mt-3">
                    <span class="view-detail-label">Description:</span>
                    <span class="view-detail-value" id="viewDescription"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>