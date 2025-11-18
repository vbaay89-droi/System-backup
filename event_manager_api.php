<?php
session_start();
require_once 'config.php'; // Use your main config file

// Get action first, as some actions might be public
$action = $_POST['action'] ?? $_GET['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    
    // --- ACTION: GET STATS FOR THE DASHBOARD (PRIVATE) ---
    case 'get_dashboard_stats':
        // ### SECURITY CHECK MOVED INSIDE ###
        // This action is for the logged-in Event Manager
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Event Manager') {
            echo json_encode(['success' => false, 'message' => 'Authentication required.']);
            exit();
        }
        $user_id = (int)$_SESSION['user_id']; // Get user_id only when needed

        try {
            $stats = [
                'assigned_events' => 0,
                'total_categories' => 0,
                'pending_results' => 0,
                'approved_medals' => 0
            ];

            // 1. Get assigned event IDs
            $stmt_events = $conn->prepare("SELECT DISTINCT event_id FROM event_manager_assignments WHERE user_id = ?");
            $stmt_events->bind_param("i", $user_id);
            $stmt_events->execute();
            $result_events = $stmt_events->get_result();
            $event_ids = [];
            while ($row = $result_events->fetch_assoc()) {
                $event_ids[] = $row['event_id'];
            }
            $stmt_events->close();
            
            $stats['assigned_events'] = count($event_ids);

            if (!empty($event_ids)) {
                $event_id_placeholders = implode(',', array_fill(0, count($event_ids), '?'));
                $types = str_repeat('i', count($event_ids));
                
                // 2. Get total categories & pending results
                $stmt_cats = $conn->prepare("SELECT COUNT(*) as count, status FROM categories WHERE event_id IN ($event_id_placeholders) GROUP BY status");
                $stmt_cats->bind_param($types, ...$event_ids);
                $stmt_cats->execute();
                $result_cats = $stmt_cats->get_result();
                
                $total_categories = 0;
                $pending_results = 0; 

                while ($row = $result_cats->fetch_assoc()) {
                    $total_categories += $row['count'];
                    if (strtolower($row['status']) == 'completed (pending results)') {
                        $pending_results += $row['count'];
                    }
                }
                $stmt_cats->close();
                $stats['total_categories'] = $total_categories;
                $stats['pending_results'] = $pending_results;

                // 3. Get total approved medals by SUMMING the counts
                $stmt_medals = $conn->prepare(
                    "SELECT 
                     SUM(gold_count) as gold, 
                     SUM(silver_count) as silver, 
                     SUM(bronze_count) as bronze 
                     FROM categories 
                     WHERE event_id IN ($event_id_placeholders) AND status = 'Results Approved'"
                );
                $stmt_medals->bind_param($types, ...$event_ids);
                $stmt_medals->execute();
                $medals = $stmt_medals->get_result()->fetch_assoc();
                $stmt_medals->close();
                
                $stats['approved_medals'] = ($medals['gold'] ?? 0) + ($medals['silver'] ?? 0) + ($medals['bronze'] ?? 0);
            }

            echo json_encode(['success' => true, 'stats' => $stats]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error fetching stats: ' . $e->getMessage()]);
        }
        break;

    // --- ACTION: GET MEDAL RESULTS (PUBLIC) ---
    case 'get_medal_results':
        if (!isset($_GET['category_id']) || empty($_GET['category_id'])) {
            echo json_encode(['success' => false, 'message' => 'Category ID missing.']);
            exit;
        }
        
        $category_id = (int)$_GET['category_id'];
        
        // ### FIX: Initialize count variables ###
        $results = [
            'gold' => null, 'gold_count' => 0,
            'silver' => null, 'silver_count' => 0,
            'bronze' => null, 'bronze_count' => 0,
            'status' => 'pending' 
        ];

        
        // ### FIX: The SQL query now also selects the count columns ###
        $sql = "
            SELECT 
                c.status,
                c.gold_count,
                c.silver_count,
                c.bronze_count,
                (SELECT co.college_name FROM colleges co WHERE co.college_id = c.gold_winner_college_id) as gold_winner,
                (SELECT co.college_name FROM colleges co WHERE co.college_id = c.silver_winner_college_id) as silver_winner,
                (SELECT co.college_name FROM colleges co WHERE co.college_id = c.bronze_winner_college_id) as bronze_winner
            FROM 
                categories c
            WHERE 
                c.category_id = ?
        ";

        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
             echo json_encode(['success' => false, 'message' => 'SQL prepare error: ' . $conn->error]);
             exit;
        }

        $stmt->bind_param("i", $category_id);
        
        if (!$stmt->execute()) {
             echo json_encode(['success' => false, 'message' => 'SQL execute error: ' . $stmt->error]);
             exit;
        }

        $result = $stmt->get_result();

        // ### FIX: Populate the new count variables in the response ###
        if ($row = $result->fetch_assoc()) {
            $results['gold'] = $row['gold_winner'];
            $results['gold_count'] = $row['gold_count'];
            $results['silver'] = $row['silver_winner'];
            $results['silver_count'] = $row['silver_count'];
            $results['bronze'] = $row['bronze_winner'];
            $results['bronze_count'] = $row['bronze_count'];
            $results['status'] = $row['status'];
        }
        
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $results]);
        break;

    // --- DEFAULT: INVALID ACTION (PROTECTED) ---
    default:
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
             echo json_encode(['success' => false, 'message' => 'Authentication required for this action.']);
             exit();
        }
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        break;
}
?>