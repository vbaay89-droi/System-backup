<?php
session_start();
require_once 'config.php'; // Your DB connection

// 1. SECURITY & ACCESS CONTROL (FIXED to allow both roles!)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || ($_SESSION['role'] !== 'Tournament Manager' && $_SESSION['role'] !== 'Sports Director')) {
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
        
        // --- Security Check & Fetch Data ---
        // NEW: Added c.event_id and ge.event_name to the SELECT query
        $stmt_check = $conn->prepare("
            SELECT c.status, c.category_name, c.division_name, c.event_id, ge.event_name as parent_event_name 
            FROM categories c
            JOIN game_events ge ON c.event_id = ge.event_id
            JOIN tournament_manager_assignments ema ON ge.event_id = ema.event_id
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
        $cat_name = trim($category['category_name'] ?? '');
        $div_name = trim($category['division_name'] ?? '');
        $parent_name = trim($category['parent_event_name'] ?? '');
        $event_id = $category['event_id'];
        
        // --- Format the Display Name elegantly ---
        $display_name = "";
        
        // Ignore internal placeholder names
        $is_main_event = ($cat_name === 'Main Event' || $cat_name === 'Main Competition' || strpos($cat_name, 'No Category') !== false);
        
        if (!$is_main_event && !empty($cat_name)) {
            $display_name .= $cat_name;
        }
        if (!empty($div_name)) {
            $display_name .= (!empty($display_name) ? ' - ' : '') . $div_name;
        }
        if (empty($display_name)) {
            $display_name = "Event"; // Fallback (Removed "Main")
        }
        
        // --- Logic to handle different transitions ---
        $allowed_transition = false;
        
        // Transition 1: Upcoming -> Ongoing
        if ($new_status == 'Ongoing' && $current_status == 'Upcoming') {
            $allowed_transition = true;
            $_SESSION['alert_message'] = "Event '{$display_name}' has been started.";
        
        // Transition 2: Ongoing -> Completed (Pending Results)
        } elseif ($new_status == 'Completed (Pending Results)' && $current_status == 'Ongoing') {
            $allowed_transition = true;
            $_SESSION['alert_message'] = "Event '{$display_name}' marked as completed. You can now submit results.";
        
        } else {
            throw new Exception("Invalid status transition requested.");
        }

        // --- Execute Update ---
        if ($allowed_transition) {
            $stmt_update = $conn->prepare("UPDATE categories SET status = ? WHERE category_id = ?");
            $stmt_update->bind_param("si", $new_status, $category_id);
            $stmt_update->execute();
            
            // --- NEW: Log this action with full SMART Details ---
            try {
                if(function_exists('log_activity')) {
                    $context = [
                        'parent_event_name' => $parent_name,
                        'category_name' => $cat_name,
                        'division_name' => $div_name,
                        'old_status' => $current_status,
                        'new_status' => $new_status
                    ];
                    log_activity($conn, $user_id, 'UPDATED_CATEGORY', $category_id, 'category', $event_id, 'event', $context);
                }
            } catch (Exception $log_e) { 
                error_log("Failed to log status update: " . $log_e->getMessage()); 
            }
            
            $_SESSION['alert_type'] = 'success';
            
        } else {
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