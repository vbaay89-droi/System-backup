<?php

/**
 * ==================================================================
 * my_events_view.php - FINAL REDESIGN & LOGIC FIX
 * * Updates:
 * 1. "Main Event" renamed to "Single Division" based on user preference.
 * 2. Logic Fix: Auto-create buttons now correctly set the category type.
 * 3. "Add Category" button is ALWAYS visible.
 * 4. Category Column is ALWAYS visible.
 * ==================================================================
 */

/**
 * Main function to render the entire list of assigned events.
 */
function render_event_list($managed_data, $college_map)
{
    // Add custom CSS for enhanced table design
    echo '<style>
        /* =========================================
           1. MODERN TABLE DESIGN SYSTEM (Desktop)
           ========================================= */
        
        /* Container for scrolling */
        .events-table-container {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            margin-bottom: 2rem;
            position: relative; 
            
            /* Horizontal Scroll */
            overflow-x: auto; 
            scroll-behavior: smooth;

            /* Hide the scrollbar for Firefox & IE/Edge */
            scrollbar-width: none; 
            -ms-overflow-style: none; 
        }

        /* Hide the scrollbar for Chrome, Safari, and Opera */
        .events-table-container::-webkit-scrollbar {
            display: none;
        }
        
        .events-table {
            margin-bottom: 0;
            font-size: 1.05rem;
            border-collapse: separate;
            border-spacing: 0;
            width: 100%; /* Ensure it fills container */
        }
        
        /* Enhanced Table Header */
        .events-table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #1e293b;
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            padding: 1.25rem 1.5rem;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            vertical-align: middle;
        }
        
        /* Table Body Cells */
        .events-table tbody td {
            padding: 1.5rem 1.5rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f3f5;
            transition: background-color 0.2s ease;
        }
        
        /* Row Hover Effect */
        .events-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .events-table tbody tr:hover {
            background-color: #f8f9fa;
            transform: scale(1.001); /* Subtle scale */
        }
        
        /* Category Column Styling */
        .category-cell {
            font-weight: 700;
            color: #1e293b;
            font-size: 1.15rem;
        }
        
        /* Type Badge Styling */
        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .type-badge.badge-match {
            background-color: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        
        .type-badge.badge-medal {
            background-color: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        
        /* Enhanced Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            white-space: nowrap;
        }
        
        .status-badge i {
            font-size: 0.8rem;
        }
        
        /* Winners Column Styling */
        .winners-container {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
        }
        
        .winner-item-line {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .winner-item-line:hover {
            transform: translateX(5px);
        }
        
        .winner-item-line.gold {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-left: 4px solid #f59e0b;
        }
        
        .winner-item-line.silver {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-left: 4px solid #94a3b8;
        }
        
        .winner-item-line.bronze {
            background: linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%);
            border-left: 4px solid #ea580c;
        }
        
        .winner-icon {
            font-size: 1.1rem;
        }
        
        .winner-item-line.gold .winner-icon { color: #f59e0b; }
        .winner-item-line.silver .winner-icon { color: #64748b; }
        .winner-item-line.bronze .winner-icon { color: #ea580c; }
        
        /* Action Buttons Enhancement */
        .action-cell {
            white-space: nowrap;     /* Prevent text wrapping */
            text-align: right;
            width: 1%;               /* Forces column to shrink to minimum content width */
            vertical-align: middle;
            padding: 0.75rem !important; 
        }

        /* The container for the buttons */
        .action-btn-group {
            display: inline-flex;
            flex-direction: column; 
            gap: 6px;                /* Standard gap between rows */
            align-items: stretch;    /* Make buttons fill the container width */
            min-width: 100px;        /* Minimum width to fit "View Results" comfortably */
            width: auto;             /* Allow it to grow only if needed */
        }
        
        /* The container for Edit/Delete icons */
        .action-row-bottom {
            display: flex;
            gap: 6px;
        }

        /* Button Styling */
        .action-btn-group .btn {
            transition: all 0.2s ease;
            font-weight: 600;
            font-size: 0.95rem;      
            padding: 0.6rem 1.2rem;  
            line-height: 1.5;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;      
            width: 100%;            
            min-height: 42px;        
        }
        
        /* Edit/Delete specific styling */
        .action-row-bottom .btn {
            flex: 1;                 
            padding: 0.5rem 0;       
            min-height: 40px;        
        }
        
        /* Icon adjustments */
        .action-btn-group .btn i {
            font-size: 1rem;         
        }
        
        /* Hover effects */
        .action-btn-group .btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.12);
            z-index: 5;
        }

        /* Force Header to match column minimize */
        .events-table thead th:last-child {
            width: 1%;
            white-space: nowrap;
        }
        
        /* Icon-only buttons */
        .btn-icon-only {
            width: 42px;
            height: 42px;
            padding: 0;
            border-radius: 10px;
            font-size: 1.1rem;
        }
        
        /* Placeholder Text */
        .placeholder-text {
            font-size: 0.95rem;
            color: #64748b;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
        }
        
        .placeholder-text i {
            font-size: 1rem;
            opacity: 0.7;
        }
        
        /* Table Danger */
        .table-danger-light { background-color: #fff5f5 !important; }
        .table-danger-light:hover { background-color: #ffe3e3 !important; }
        
        /* Note Button */
        .note-alert-btn {
            margin-top: 0.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            font-weight: 700;
            border-radius: 6px;
            animation: pulse-danger 2s ease-in-out infinite;
        }
        @keyframes pulse-danger {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.9; transform: scale(1.02); }
        }
        
        /* Headers */
        .game-heading {
            color: #1e293b;
            font-weight: 800;
            font-size: 2rem;
            padding-bottom: 0.75rem;
            border-bottom: 4px solid #0d6efd;
            display: inline-block;
            margin-bottom: 2rem;
            margin-top: 1rem;
        }
        
        .event-card-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-bottom: 2px solid #e9ecef;
            padding: 1.25rem 1.5rem !important;
        }
        
        .event-title {
            color: #1e293b;
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: 0.5px;
        }
        
        /* Responsive scroll wrapper (Keep for tablets, but override for mobile) */
        .table-responsive {
            border-radius: 16px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 5px;
        }

        /* =========================================
           2. MOBILE VIEW TRANSFORMATION (Card Layout)
           ========================================= */
        @media screen and (max-width: 768px) {
            
            /* Reset Table Structure to Block (Card Style) */
            .events-table, 
            .events-table tbody, 
            .events-table tr, 
            .events-table td {
                display: block;
                width: 100%;
            }

            /* Hide the Desktop Header */
            .events-table thead {
                display: none;
            }

            /* Style Each Row as a Standalone Card */
            .events-table tbody tr {
                background: #ffffff;
                margin-bottom: 1.5rem;
                border-radius: 16px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
                border: 1px solid #e2e8f0;
                overflow: hidden; 
            }

            /* General Cell Styling for Mobile */
            .events-table tbody td {
                padding: 1rem 1.25rem;
                text-align: left; /* Reset right align */
                border-bottom: 1px solid #f1f5f9;
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start; /* Left align content */
            }

            /* Remove border from the last item in the card */
            .events-table tbody td:last-child {
                border-bottom: none;
            }

            /* Transform "Category" Column into a Card Header */
            .category-cell {
                background: linear-gradient(to right, #f8fafc, #ffffff);
                font-size: 1.25rem !important; 
                color: #0f172a;
                border-bottom: 2px solid #e2e8f0 !important;
                padding: 1.25rem !important;
                display: block !important; /* Ensure block display for header */
            }

            /* Fix "Action" Buttons for Touch Screens */
            .action-cell {
                background-color: #f8fafc;
                padding: 1.25rem !important;
                margin-top: 0;
                width: 100% !important; /* Override the 1% width */
                align-items: stretch !important; 
            }

            /* Make the button group fill the width */
            .action-btn-group {
                width: 100%;
                min-width: unset; 
            }

            /* Make buttons big and easy to tap */
            .action-btn-group .btn {
                padding: 0.8rem; 
                font-size: 1rem;
                height: 50px;
            }

            /* Arrange Edit/Delete buttons side-by-side */
            .action-row-bottom {
                display: flex;
                gap: 10px;
                margin-top: 8px;
            }
            
            .action-row-bottom .btn {
                flex: 1; /* Both take equal width */
            }

            /* Adjust Winners Container for Mobile */
            .winners-container {
                width: 100%;
            }
            
            .winner-item-line {
                width: 100%; 
                background: #f8fafc; 
            }
        }
    </style>';

    // Check if any events are assigned
    if (empty($managed_data)) {
        echo '<div class="card shadow-sm border-0">';
        echo '  <div class="card-body text-center p-5">';
        echo '    <i class="fas fa-calendar-times fa-4x text-muted mb-4 opacity-50"></i>';
        echo '    <h4 class="card-title fw-bold mb-2">No Events Assigned</h4>';
        echo '    <p class="text-muted fs-5">You do not have any events assigned to you at this time.</p>';
        echo '  </div>';
        echo '</div>';
        return;
    }

    // Loop through each Game
    foreach ($managed_data as $game_name => $events) {
        echo '<div class="mb-5">';
        echo '  <h2 class="game-heading">' . htmlspecialchars($game_name) . '</h2>';

        // Loop through each Event
        foreach ($events as $event) {
            $event_id = (int)$event['event_id'];
            $event_name = htmlspecialchars(strtoupper($event['event_name']));
            $categories = $event['categories'];
            
            // Get the structure setting from Database
            $structure_mode = $event['event_structure'] ?? 'Single Category'; 

            echo '<div class="card shadow mb-5 border-0" style="border-radius: 16px;">';
            echo '  <div class="card-header event-card-header d-flex justify-content-between align-items-center">';
            echo '    <h5 class="mb-0 event-title">' . $event_name . '</h5>';
            
            // BUTTON ALWAYS VISIBLE: Allows adding sub-categories (Women's, Men's, etc.)
            echo '    <button class="btn btn-primary shadow-sm" 
                            style="padding: 0.5rem 1.25rem; font-weight: 600;"
                            data-bs-toggle="modal" 
                            data-bs-target="#categoryModal" 
                            data-action="add"
                            data-event-id="' . $event_id . '" 
                            data-event-name="' . $event_name . '">';
            echo '      <i class="fas fa-plus me-2"></i> Add Category';
            echo '    </button>';
            
            echo '  </div>';

            // --- UPDATED LOGIC: ALWAYS SHOW TABLE (No "Initialize" Button) ---
            echo '<div class="table-responsive events-table-container">';
            echo '  <table class="table events-table table-hover align-middle mb-0">';
            echo '    <thead>';
            echo '      <tr>';
            echo '        <th scope="col" class="text-center" style="min-width: 220px;">Category / Division</th>';
            echo '        <th scope="col" class="text-center" style="min-width: 160px;">Status</th>';
            echo '        <th scope="col" class="text-center" style="min-width: 320px;">Approved Winners</th>';
            echo '        <th scope="col" class="text-center" style="width: 1%; white-space: nowrap;">Actions</th>';
            echo '      </tr>';
            echo '    </thead>';
            echo '    <tbody>';

            // IF NO CATEGORIES -> SHOW EMPTY ROW
            if (empty($categories)) {
                echo '<tr>';
                echo '  <td colspan="4" class="text-center py-5 text-muted">';
                echo '      <div class="d-flex flex-column align-items-center justify-content-center">';
                echo '          <i class="fas fa-clipboard-list fa-2x mb-3 opacity-25"></i>';
                echo '          <h6 class="fw-bold">No Categories Yet</h6>';
                echo '          <p class="small mb-0">Click <span class="badge bg-primary text-white"><i class="fas fa-plus"></i> Add Category</span> above to set up Men\'s, Women\'s, etc.</p>';
                echo '      </div>';
                echo '  </td>';
                echo '</tr>';
            } 
            // IF CATEGORIES EXIST -> LOOP THEM
            else {
                foreach ($categories as $category) {
                    $category_status = $category['status'];
                    
                    $table_row_class = '';
                    if ($category_status === 'Cancelled' || $category_status === 'Results Rejected') {
                        $table_row_class = 'table-danger-light';
                    }

                    echo '  <tr class="' . $table_row_class . '">';
                    echo '    <td class="category-cell">' . htmlspecialchars($category['category_name']) . '</td>';
                    echo '    <td>' . get_status_badge($category['status'], $category['notes'], $category['category_id'], $category['category_name'], $event_name) . '</td>';
                    echo '    <td>' . render_winner_list($category, $college_map) . '</td>';
                    echo '    <td class="action-cell text-end">' . render_category_actions($category, $event_id, $event_name) . '</td>';
                    echo '  </tr>';
                }
            }

            echo '    </tbody>';
            echo '  </table>';
            echo '</div>'; // End table-responsive
            
            echo '</div>'; // End card
        }
        echo '</div>'; // End game section
    }
}



/**
 * Renders the winner list with enhanced styling
 */
function render_winner_list($category, $college_map)
{
    $winners_html = []; 
    $status = $category['status']; 

    if ($status === 'Completed' || $status === 'Results Approved') {
        if (!empty($category['gold_winner_college_id'])) {
            $winners_html[] = '<div class="winner-item-line gold">
                <i class="fas fa-medal winner-icon"></i>
                <strong>' . htmlspecialchars($college_map[$category['gold_winner_college_id']] ?? 'N/A') . '</strong>
                <span class="ms-auto">(' . (int)$category['gold_count'] . ')</span>
            </div>';
        }
        if (!empty($category['silver_winner_college_id'])) {
            $winners_html[] = '<div class="winner-item-line silver">
                <i class="fas fa-medal winner-icon"></i>
                <strong>' . htmlspecialchars($college_map[$category['silver_winner_college_id']] ?? 'N/A') . '</strong>
                <span class="ms-auto">(' . (int)$category['silver_count'] . ')</span>
            </div>';
        }
        if (!empty($category['bronze_winner_college_id'])) {
            $winners_html[] = '<div class="winner-item-line bronze">
                <i class="fas fa-medal winner-icon"></i>
                <strong>' . htmlspecialchars($college_map[$category['bronze_winner_college_id']] ?? 'N/A') . '</strong>
                <span class="ms-auto">(' . (int)$category['bronze_count'] . ')</span>
            </div>';
        }
    }

    if (!empty($winners_html)) {
        return '<div class="winners-container">' . implode('', $winners_html) . '</div>';
    }

    $placeholder_messages = [
        'Upcoming' => ['icon' => 'fa-clock', 'text' => 'Event has not started.'],
        'Ongoing' => ['icon' => 'fa-spinner', 'text' => 'Event is in progress...'],
        'Completed (Pending Results)' => ['icon' => 'fa-hourglass-half', 'text' => 'Awaiting admin approval...'],
        'Results Rejected' => ['icon' => 'fa-times-circle', 'text' => 'Results were rejected.', 'class' => 'text-danger'],
        'Results Submitted' => ['icon' => 'fa-hourglass-half', 'text' => 'Awaiting admin approval...'],
        'Completed' => ['icon' => 'fa-info-circle', 'text' => 'No winners recorded.'],
        'Results Approved' => ['icon' => 'fa-info-circle', 'text' => 'No winners recorded.'],
        'Postponed' => ['icon' => 'fa-pause-circle', 'text' => 'Event is postponed.'],
        'Cancelled' => ['icon' => 'fa-ban', 'text' => 'Event was cancelled.']
    ];

    $msg = $placeholder_messages[$status] ?? ['icon' => 'fa-question-circle', 'text' => 'No results available.'];
    $class = $msg['class'] ?? 'text-muted';
    
    return '<span class="placeholder-text ' . $class . '">
                <i class="fas ' . $msg['icon'] . '"></i>
                ' . $msg['text'] . '
            </span>';
}

/**
 * Returns enhanced status badge
 */
function get_status_badge($status, $notes = null, $category_id = null, $category_name = null, $event_name = '')
{
    $badge_configs = [
        'Upcoming' => ['class' => 'text-bg-primary', 'icon' => 'fa-calendar-alt', 'text' => 'Upcoming'],
        'Ongoing' => ['class' => 'text-bg-success', 'icon' => 'fa-play-circle', 'text' => 'Ongoing'],
        'Completed (Pending Results)' => ['class' => 'text-bg-warning', 'icon' => 'fa-hourglass-half', 'text' => 'Pending Approval'],
        'Results Submitted' => ['class' => 'text-bg-warning', 'icon' => 'fa-paper-plane', 'text' => 'Submitted'],
        'Completed' => ['class' => 'text-bg-info', 'icon' => 'fa-check-circle', 'text' => 'Completed'],
        'Results Approved' => ['class' => 'text-bg-info', 'icon' => 'fa-check-circle', 'text' => 'Completed'],
        'Postponed' => ['class' => 'text-bg-dark', 'icon' => 'fa-pause', 'text' => 'Postponed'],
        'Cancelled' => ['class' => 'text-bg-danger', 'icon' => 'fa-ban', 'text' => 'Cancelled']
    ];
    
    // Special handling for Results Rejected
    if ($status === 'Results Rejected') {
        $badge = '<span class="status-badge text-bg-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    Results Rejected
                  </span>';
        
        $note_button_html = '';
        if (!empty($notes)) {
            // Correctly creating the string using variables, not <?php tags
            $note_button_html = '<button type="button" 
                    class="note-alert-btn btn btn-sm btn-outline-danger ms-2" 
                    data-bs-toggle="modal" 
                    data-bs-target="#noteModal" 
                    data-category-name="' . htmlspecialchars($category_name, ENT_QUOTES) . '"
                    data-note="' . htmlspecialchars($notes, ENT_QUOTES) . '"
                    data-event-name="' . htmlspecialchars($event_name, ENT_QUOTES) . '">
                <i class="fas fa-exclamation-circle"></i> Note
            </button>';
        }
        
        return '<div>' . $badge . $note_button_html . '</div>';
    }
    
    // Default badge rendering
    $config = $badge_configs[$status] ?? ['class' => 'text-bg-secondary', 'icon' => 'fa-circle', 'text' => $status];
    
    return '<span class="status-badge ' . $config['class'] . '">
                <i class="fas ' . $config['icon'] . '"></i>
                ' . htmlspecialchars($config['text']) . '
            </span>';
}

/**
 * Helper: Render Action Buttons (CLEANED: No Match Logic)
 */
function render_category_actions($category, $event_id, $event_name)
{
    $cat_id = (int)$category['category_id'];
    $status = $category['status'];
    
    // Default Button Config - Direct Link to Tally Page
    $btn_text = 'Manage Results';
    $btn_class = 'btn-primary';
    $btn_icon = 'fa-edit';
    $btn_link = "submit_results.php?category_id={$cat_id}";
    $extra_attrs = '';
    $html_top = "";
    

    // Status Logic
    if ($status === 'Upcoming') {
        $btn_text = 'Start Event';
        $btn_class = 'btn-success';
        $btn_icon = 'fa-play';
        // Add data-event-name here too just in case
        $extra_attrs = "data-bs-toggle='modal' data-bs-target='#startModal' data-category-id='{$cat_id}' data-category-name='" . htmlspecialchars($category['category_name']) . "'";
        // Use button for modal trigger
        $html_top = "<button type='button' class='btn btn-action {$btn_class}' {$extra_attrs}><i class='fas {$btn_icon} me-2'></i>{$btn_text}</button>";
    } 
    elseif ($status === 'Ongoing') {
        $btn_text = 'Complete Event'; // Changed from "Enter Results"
        $btn_class = 'btn-warning text-dark';
        $btn_icon = 'fa-check-double'; // Changed icon to checkmarks
        
        // Logic: Trigger the 'completeModal' instead of going to the link
        $extra_attrs = "data-bs-toggle='modal' data-bs-target='#completeModal' data-category-id='{$cat_id}' data-category-name='" . htmlspecialchars($category['category_name']) . "'";
        
        $html_top = "<button type='button' class='btn {$btn_class} w-100 mb-1' {$extra_attrs}><i class='fas {$btn_icon} me-2'></i>{$btn_text}</button>";
    } 
    elseif ($status === 'Completed (Pending Results)') {
        // Event is done, but no results yet -> Show "Submit Results"
        $html_top = "<a href='{$btn_link}' class='btn btn-primary w-100 mb-1'><i class='fas fa-upload me-2'></i>Submit Results</a>";
    }
    elseif ($status === 'Results Submitted') {
        // Results are in, waiting for approval -> Show "View/Edit"
        $html_top = "<a href='{$btn_link}' class='btn btn-info text-white w-100 mb-1'><i class='fas fa-eye me-2'></i>View/Edit</a>";
    }
    elseif (in_array($status, ['Completed', 'Results Approved'])) {
        $btn_text = 'Final Results';
        $btn_class = 'btn-success'; // Changed from 'btn-outline-success'
        $btn_icon = 'fa-check-circle';
        $html_top = "<a href='{$btn_link}' class='btn btn-action {$btn_class}'><i class='fas {$btn_icon} me-2'></i> {$btn_text}</a>";
    }
    elseif ($status === 'Results Rejected') {
        $btn_text = 'Fix & Resubmit';
        $btn_class = 'btn-danger';
        $btn_icon = 'fa-exclamation-triangle';
        $html_top = "<a href='{$btn_link}' class='btn btn-action {$btn_class}'><i class='fas {$btn_icon} me-2'></i> {$btn_text}</a>";
    } 
    else {
        // Disabled/Cancelled State
        $html_top = "<button class='btn btn-action btn-secondary' disabled>Event Locked</button>";
    }

    // Secondary Actions (Edit/Delete)
    $html_bottom = "";
    if (!in_array($status, ['Results Submitted', 'Results Approved'])) {
        
        // FIXED: Added 'data-event-name' to this string so the modal can read it
        $edit_data = "data-bs-toggle='modal' 
                      data-bs-target='#categoryModal' 
                      data-action='edit' 
                      data-event-id='{$event_id}' 
                      data-event-name='" . htmlspecialchars($event_name, ENT_QUOTES) . "' 
                      data-category-id='{$cat_id}' 
                      data-category-name='" . htmlspecialchars($category['category_name'], ENT_QUOTES) . "' 
                      data-status='{$status}' 
                      data-event-date='" . htmlspecialchars($category['event_date']) . "' 
                      data-event-time='" . htmlspecialchars($category['event_time']) . "' 
                      data-venue='" . htmlspecialchars($category['venue'], ENT_QUOTES) . "'";
                      
        $del_data = "data-bs-toggle='modal' 
                     data-bs-target='#deleteModal' 
                     data-category-id='{$cat_id}' 
                     data-category-name='" . htmlspecialchars($category['category_name'], ENT_QUOTES) . "'";
        // Print URL
        $print_url = "print_tally_sheet.php?category_id={$cat_id}";

        $html_bottom = "
        <div class='action-row-bottom'>
            <a href='{$print_url}' target='_blank' class='btn btn-sm btn-outline-dark' title='Print Tally Sheet'>
                <i class='fas fa-print'></i>
            </a>
            <button class='btn btn-sm btn-outline-secondary' {$edit_data} title='Edit Details'><i class='fas fa-edit'></i></button>
            <button class='btn btn-sm btn-outline-danger' {$del_data} title='Delete Division'><i class='fas fa-trash'></i></button>
        </div>";
        
    }

    return "<div class='action-btn-group'>{$html_top}{$html_bottom}</div>";
}
?>