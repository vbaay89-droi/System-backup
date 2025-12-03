<?php
session_start();
require_once 'config.php'; // Your DB connection
 // Your logger function

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Event Manager') {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST['category_id']) || empty($_POST['new_status'])) {
            throw new Exception("Incomplete request.");
        }
        
        $category_id = (int)$_POST['category_id'];
        $new_status = $_POST['new_status'];
        
        // --- Security Check ---
        // Verify this manager is allowed to edit this category and get current status
        $stmt_check = $conn->prepare("
            SELECT c.status, c.category_name 
            FROM categories c
            JOIN game_events ge ON c.event_id = ge.event_id
            JOIN event_manager_assignments ema ON ge.event_id = ema.event_id
            WHERE c.category_id = ? AND ema.user_id = ?
        ");
        $stmt_check->bind_param("ii", $category_id, $user_id);
        $stmt_check->execute();
        $result = $stmt_check->get_result();
        
        if ($result->num_rows == 0) {
            throw new Exception("Permission denied.");
        }
        
        $category = $result->fetch_assoc();
        $current_status = $category['status'];
        $category_name = $category['category_name'];
        
        // --- Logic to handle different transitions ---
        $allowed_transition = false;
        
        // Transition 1: Upcoming -> Ongoing
        if ($new_status == 'Ongoing' && $current_status == 'Upcoming') {
            $allowed_transition = true;
            $_SESSION['alert_message'] = "Event '{$category_name}' has been started.";
        
        // Transition 2: Ongoing -> Completed (Pending Results)
        } elseif ($new_status == 'Completed (Pending Results)' && $current_status == 'Ongoing') {
            $allowed_transition = true;
            $_SESSION['alert_message'] = "Event '{$category_name}' marked as completed. You can now submit results.";
        
        } else {
            throw new Exception("Invalid status transition requested.");
        }

        // --- Execute Update ---
        if ($allowed_transition) {
            $stmt_update = $conn->prepare("UPDATE categories SET status = ? WHERE category_id = ?");
            $stmt_update->bind_param("si", $new_status, $category_id);
            $stmt_update->execute();
            
            // Log this action
            try {
                // Assuming you have a log_activity function
                log_activity($conn, $user_id, 'UPDATED_CATEGORY', $category_id, 'category', $current_status, $new_status);
            } catch (Exception $log_e) { 
                error_log("Failed to log status update: " . $log_e->getMessage()); 
            }
            
            $_SESSION['alert_type'] = 'success';
            
        } else {
            // This should be caught by the logic above, but as a fallback.
            $_SESSION['alert_message'] = "Invalid action.";
            $_SESSION['alert_type'] = 'danger';
        }
        
    } catch (Exception $e) {
        $_SESSION['alert_message'] = "ERROR: " . $e->getMessage();
        $_SESSION['alert_type'] = 'danger';
    }
}

// Redirect back to the events page
header('Location: my_events.php');
exit();
?>