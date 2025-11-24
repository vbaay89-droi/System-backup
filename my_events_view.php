<?php

/**
 * ==================================================================
 * my_events_view.php - REDESIGNED UI/UX VERSION (FIXED)
 * Enhanced table design with modern, professional styling
 * Includes Sticky Action Column for better visibility
 * ==================================================================
 */

/**
 * Main function to render the entire list of assigned events.
 * UPDATED: Large UI + Sticky Action Column
 */
function render_event_list($managed_data, $college_map)
{
    // Add custom CSS for enhanced table design
    echo '<style>
        /* Modern Table Design System - SCALED UP VERSION */
        /* 1. Container for scrolling */
        .events-table-container {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            margin-bottom: 2rem;
            position: relative; 
            
            /* Keep overflow enabled for Sticky Columns to work */
            overflow-x: auto; 
            scroll-behavior: smooth;

            /* NEW: Hide the scrollbar for Firefox & IE/Edge */
            scrollbar-width: none; 
            -ms-overflow-style: none; 
        }

        /* NEW: Hide the scrollbar for Chrome, Safari, and Opera */
        .events-table-container::-webkit-scrollbar {
            display: none;
        }
        
        .events-table {
            margin-bottom: 0;
            font-size: 1.05rem;
            border-collapse: separate; /* Required for sticky borders to work nicely */
            border-spacing: 0;
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
            transform: scale(1.002);
            position: relative;
            z-index: 5; /* Ensure hovered row is above others */
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
        
        /* Action Buttons Enhancement - PROFESSIONAL FIT */
        .action-cell {
            white-space: nowrap;     /* Prevent text wrapping */
            text-align: right;
            width: 1%;               /* CRITICAL: Forces column to shrink to minimum content width */
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

        /* Button Styling - PRO SIZE */
        .action-btn-group .btn {
            transition: all 0.2s ease;
            font-weight: 600;
            font-size: 0.95rem;      /* Increased from 0.85rem */
            padding: 0.6rem 1.2rem;  /* Increased padding */
            line-height: 1.5;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;      /* Softer corners */
            width: 100%;             
            min-height: 42px;        /* Enforce professional height */
        }
        
        /* Edit/Delete specific styling */
        .action-row-bottom .btn {
            flex: 1;                 
            padding: 0.5rem 0;       /* Balanced vertical padding */
            min-height: 40px;        /* Matches the top button height */
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
        
        .action-btn-group .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            z-index: 105;
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
        
        /* --- STICKY COLUMN LOGIC --- */
        /* 1. Enable scrolling on container */
        .table-responsive {
            border-radius: 16px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 5px;
        }

        /* 2. Sticky Header Cell */
        .events-table thead th:last-child {
            position: sticky;
            right: 0;
            z-index: 20; /* Higher than body cells */
            background: #e9ecef; /* Match header gradient end */
            border-left: 1px solid #dee2e6;
            box-shadow: -5px 0 10px rgba(0,0,0,0.05);
        }

        /* 3. Sticky Body Cell */
        .events-table tbody td:last-child {
            position: sticky;
            right: 0;
            z-index: 15;
            background-color: #ffffff; /* Default background */
            border-left: 1px solid #f1f3f5;
            box-shadow: -5px 0 10px rgba(0,0,0,0.05);
        }

        /* 4. Hover State Fix for Sticky Column */
        .events-table tbody tr:hover td:last-child {
            background-color: #f8f9fa; /* Match row hover color */
        }
        
        /* 5. Danger Row Hover Fix */
        .events-table tbody tr.table-danger-light td:last-child {
            background-color: #fff5f5;
        }
        .events-table tbody tr.table-danger-light:hover td:last-child {
            background-color: #ffe3e3;
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

            // Smart logic for single mode
            $is_single_mode = false;
            if (count($categories) === 1) {
                $first_cat_name = $categories[0]['category_name'];
                if ($first_cat_name === 'Main Event' || $first_cat_name === 'Main Competition') {
                    $is_single_mode = true;
                }
            }

            echo '<div class="card shadow mb-5 border-0" style="border-radius: 16px;">';
            echo '  <div class="card-header event-card-header d-flex justify-content-between align-items-center">';
            echo '    <h5 class="mb-0 event-title">' . $event_name . '</h5>';
            
            if (!$is_single_mode) {
                echo '    <button class="btn btn-primary shadow-sm" 
                                style="padding: 0.5rem 1.25rem; font-weight: 600;"
                                data-bs-toggle="modal" 
                                data-bs-target="#categoryModal" 
                                data-action="add"
                                data-event-id="' . $event_id . '" 
                                data-event-name="' . $event_name . '">';
                echo '      <i class="fas fa-plus me-2"></i> Add Category';
                echo '    </button>';
            }
            
            echo '  </div>';

            // === EMPTY STATE LOGIC ===
            if (empty($categories)) {
                echo '<div class="card-body text-center p-5 bg-light" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">';
                echo '  <div class="d-flex flex-column align-items-center justify-content-center py-4">';
                
                if (stripos($structure_mode, 'Multiple') !== false) {
                    // --- OPTION A: Multiple Categories ---
                    echo '      <i class="fas fa-layer-group fa-4x text-primary mb-3 opacity-50"></i>';
                    echo '      <h5 class="text-dark fw-bold">Multiple Categories Required</h5>';
                    echo '      <p class="text-muted fs-5 mb-4" style="max-width: 500px;">
                                    The Director set this as a multi-category event.<br>
                                    Please use the <strong>"+ Add Category"</strong> button above to create divisions (e.g., Men, Women).
                                </p>';
                } else {
                    // --- OPTION B: Single Category ---
                    echo '      <i class="fas fa-clipboard-list fa-4x text-muted mb-3 opacity-50"></i>';
                    echo '      <h5 class="text-dark fw-bold">Setup Required</h5>';
                    echo '      <p class="text-muted fs-5 mb-4" style="max-width: 500px;">No categories found. Use the buttons below to initialize this event.</p>';
                    
                    echo '      <div class="d-flex gap-3">';
                    echo '    <form method="POST" action="my_events.php">';
                    echo '      <input type="hidden" name="action" value="save_category">';
                    echo '      <input type="hidden" name="event_id" value="' . $event_id . '">';
                    echo '      <input type="hidden" name="category_name" value="Main Event">'; 
                    echo '      <input type="hidden" name="category_type" value="match">';
                    echo '      <input type="hidden" name="status" value="Upcoming">';
                    echo '      <button type="submit" class="btn btn-primary btn-lg px-4">';
                    echo '          <i class="fas fa-basketball-ball me-2"></i> Auto-Create Match';
                    echo '      </button>';
                    echo '    </form>';

                    echo '    <form method="POST" action="my_events.php">';
                    echo '      <input type="hidden" name="action" value="save_category">';
                    echo '      <input type="hidden" name="event_id" value="' . $event_id . '">';
                    echo '      <input type="hidden" name="category_name" value="Main Competition">'; 
                    echo '      <input type="hidden" name="category_type" value="medal">';
                    echo '      <input type="hidden" name="status" value="Upcoming">';
                    echo '      <button type="submit" class="btn btn-outline-secondary btn-lg px-4">';
                    echo '          <i class="fas fa-medal me-2"></i> Auto-Create Medal';
                    echo '      </button>';
                    echo '    </form>';
                    echo '      </div>';
                }

                echo '  </div>';
                echo '</div>';
            } else {
                echo '<div class="table-responsive events-table-container">';
                echo '  <table class="table events-table table-hover align-middle mb-0">';
                echo '    <thead>';
                echo '      <tr>';
                
                if (!$is_single_mode) {
                    echo '        <th scope="col" style="min-width: 220px;">Category</th>';
                }

                echo '        <th scope="col" style="min-width: 140px;">Type</th>';
                echo '        <th scope="col" style="min-width: 160px;">Status</th>';
                echo '        <th scope="col" style="min-width: 320px;">Approved Winners</th>';
                echo '        <th scope="col" class="text-end" style="width: 1%; white-space: nowrap;">Actions</th>';
                echo '      </tr>';
                echo '    </thead>';
                echo '    <tbody>';

                foreach ($categories as $category) {
                    $category_status = $category['status'];
                    
                    $table_row_class = '';
                    if ($category_status === 'Cancelled' || $category_status === 'Results Rejected') {
                        $table_row_class = 'table-danger-light';
                    }

                    echo '  <tr class="' . $table_row_class . '">';

                    if (!$is_single_mode) {
                        echo '    <td class="category-cell">' . htmlspecialchars($category['category_name']) . '</td>';
                    }

                    echo '    <td>' . render_type_badge($category['category_type']) . '</td>';
                    echo '    <td>' . get_status_badge($category['status'], $category['notes'], $category['category_id'], $category['category_name']) . '</td>';
                    echo '    <td>' . render_winner_list($category, $college_map) . '</td>';
                    // Pass $is_single_mode to the function
echo '    <td class="action-cell text-end">' . render_category_actions($category, $event_id, $event_name, $is_single_mode) . '</td>';
                }

                echo '    </tbody>';
                echo '  </table>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}

/**
 * Renders a styled type badge
 */
function render_type_badge($type)
{
    $badge_class = $type === 'match' ? 'badge-match' : 'badge-medal';
    $icon = $type === 'match' ? 'fa-basketball-ball' : 'fa-medal';
    
    return '<span class="type-badge ' . $badge_class . '">
                <i class="fas ' . $icon . '"></i>
                ' . htmlspecialchars(ucfirst($type)) . '
            </span>';
}

/**
 * Generates action buttons (Updated: Protects Single Event Mode)
 */
function render_category_actions($category, $event_id, $event_name, $is_single_mode = false)
{
    $category_id = (int)$category['category_id'];
    $category_name_safe = htmlspecialchars($category['category_name']);
    $event_name_safe = htmlspecialchars($event_name);
    $status = $category['status'];
    $category_type = $category['category_type'];
    
    // --- DATA ATTRIBUTES SETUP ---
    $edit_data_attrs = "data-bs-toggle='modal' 
                            data-bs-target='#categoryModal' 
                            data-action='edit'
                            data-event-id='{$event_id}' 
                            data-event-name='{$event_name_safe}' 
                            data-category-id='{$category_id}' 
                            data-category-name='{$category_name_safe}' 
                            data-status='" . htmlspecialchars($status) . "' 
                            data-category-type='{$category_type}'
                            data-event-date='" . htmlspecialchars($category['event_date']) . "'
                            data-event-time='" . htmlspecialchars($category['event_time']) . "'
                            data-venue='" . htmlspecialchars($category['venue']) . "'";

    $delete_data_attrs = "data-bs-toggle='modal' 
                              data-bs-target='#deleteModal' 
                              data-category-id='{$category_id}' 
                              data-category-name='{$category_name_safe}'";
                              
    $start_data_attrs = "data-bs-toggle='modal' 
                                data-bs-target='#startModal' 
                                data-category-id='{$category_id}' 
                                data-category-name='{$category_name_safe}'";
                                
    $complete_data_attrs = "data-bs-toggle='modal' 
                                data-bs-target='#completeModal' 
                                data-category-id='{$category_id}' 
                                data-category-name='{$category_name_safe}'";

    // --- SMART BUTTON LOGIC ---
    $primary_btn_text = 'Manage';
    $primary_btn_class = 'btn-primary';
    $primary_btn_icon = 'fa-cog';
    $primary_btn_href = '#';
    $primary_btn_attrs = ''; 
    $primary_btn_disabled = false;

    if ($category_type === 'medal') {
        $primary_btn_href = "submit_results.php?category_id={$category_id}";
        
        if ($status === 'Upcoming') {
            $primary_btn_text = 'Start';
            $primary_btn_class = 'btn-success';
            $primary_btn_icon = 'fa-play';
            $primary_btn_attrs = $start_data_attrs;
        } elseif ($status === 'Ongoing') {
            $primary_btn_text = 'Complete';
            $primary_btn_class = 'btn-warning';
            $primary_btn_icon = 'fa-check-double';
            $primary_btn_attrs = $complete_data_attrs;
        } elseif ($status === 'Completed (Pending Results)') {
            $primary_btn_text = 'Submit';
            $primary_btn_class = 'btn-primary';
            $primary_btn_icon = 'fa-pencil-alt';
        } elseif (in_array($status, ['Completed', 'Results Approved'])) {
            $primary_btn_text = 'Results';
            $primary_btn_class = 'btn-outline-success';
            $primary_btn_icon = 'fa-chart-bar';
        } elseif ($status === 'Results Submitted') {
            $primary_btn_text = 'View';
            $primary_btn_class = 'btn-outline-primary';
            $primary_btn_icon = 'fa-eye';
        } elseif ($status === 'Results Rejected') {
            $primary_btn_text = 'Resubmit';
            $primary_btn_class = 'btn-danger';
            $primary_btn_icon = 'fa-exclamation-triangle';
        } elseif (in_array($status, ['Postponed', 'Cancelled'])) {
            $primary_btn_disabled = true;
        }

    } elseif ($category_type === 'match') {
        $primary_btn_href = "event_manager_matches.php?category_id={$category_id}";

        if (in_array($status, ['Completed', 'Results Approved'])) {
            $primary_btn_text = 'Results';
            $primary_btn_class = 'btn-outline-success';
            $primary_btn_icon = 'fa-chart-bar';
        } elseif ($status === 'Results Submitted') {
            $primary_btn_text = 'View';
            $primary_btn_class = 'btn-outline-primary';
            $primary_btn_icon = 'fa-eye';
        } elseif ($status === 'Results Rejected') {
            $primary_btn_text = 'Resubmit';
            $primary_btn_class = 'btn-danger';
            $primary_btn_icon = 'fa-exclamation-triangle';
        } else {
            $primary_btn_text = 'Manage';
            $primary_btn_class = 'btn-primary';
            $primary_btn_icon = 'fa-cog';
            
            if (in_array($status, ['Postponed', 'Cancelled'])) {
                 $primary_btn_class = 'btn-outline-secondary';
            }
        }
    }

    // --- EDIT/DELETE LOGIC ---
    $edit_btn_disabled = in_array($status, ['Ongoing', 'Completed', 'Results Approved', 'Results Submitted']);
    $edit_tooltip = $edit_btn_disabled ? "data-bs-toggle='tooltip' title='Cannot edit in-progress/completed events'" : '';

    // --- NEW LOGIC: PROTECT SINGLE CATEGORY EVENTS ---
    if ($is_single_mode) {
        // If this is the ONLY category, forbid deletion.
        $delete_btn_disabled = true;
        $delete_tooltip = "data-bs-toggle='tooltip' title='This is the Main Event. You cannot delete it. Contact Admin to remove the event.'";
    } else {
        // Normal logic for multi-category events
        $delete_btn_disabled = !in_array($status, ['Upcoming', 'Cancelled']);
        $delete_tooltip = $delete_btn_disabled ? "data-bs-toggle='tooltip' title='Can only delete Upcoming/Cancelled events'" : '';
    }

    // --- BUILD HTML ---
    $html = '<div class="action-btn-group" role="group" aria-label="Category Actions">';

    // 1. Top Row: Smart Button
    if ($primary_btn_disabled) {
        $html .= "<a href='#' class='btn {$primary_btn_class} disabled' role='button' aria-disabled='true'>";
        $html .= "  <i class='fas {$primary_btn_icon} me-1'></i> " . htmlspecialchars($primary_btn_text);
        $html .= "</a>";
    } elseif (!empty($primary_btn_attrs)) {
        $html .= "<button type='button' class='btn {$primary_btn_class}' {$primary_btn_attrs}>";
        $html .= "  <i class='fas {$primary_btn_icon} me-1'></i> " . htmlspecialchars($primary_btn_text);
        $html .= "</button>";
    } else {
        $html .= "<a href='{$primary_btn_href}' class='btn {$primary_btn_class}'>";
        $html .= "  <i class='fas {$primary_btn_icon} me-1'></i> " . htmlspecialchars($primary_btn_text);
        $html .= "</a>";
    }

    // 2. Bottom Row: Edit and Delete
    $html .= '<div class="action-row-bottom">';
    
    // Edit Button
    $html .= "<button type='button' 
                        class='btn btn-outline-secondary' 
                        {$edit_data_attrs} 
                        " . ($edit_btn_disabled ? 'disabled' : '') . "
                        {$edit_tooltip}>";
    $html .= "  <i class='fas fa-edit'></i>";
    $html .= "</button>";

    // Delete Button (Protected)
    $html .= "<button type='button' 
                        class='btn btn-outline-danger' 
                        {$delete_data_attrs} 
                        " . ($delete_btn_disabled ? 'disabled' : '') . "
                        {$delete_tooltip}>";
    $html .= "  <i class='fas fa-trash-alt'></i>";
    $html .= "</button>";

    $html .= '</div>'; 
    $html .= '</div>'; 

    return $html;
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
function get_status_badge($status, $notes = null, $category_id = null, $category_name = null)
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
            $note_data_attrs = "data-bs-toggle='modal' 
                                data-bs-target='#noteModal' 
                                data-category-name='" . htmlspecialchars($category_name, ENT_QUOTES) . "' 
                                data-note='" . htmlspecialchars($notes, ENT_QUOTES) . "'";
            
            $note_button_html = '
                <button type="button" 
                        class="btn btn-sm btn-outline-danger note-alert-btn" 
                        ' . $note_data_attrs . ' 
                        data-bs-toggle="tooltip" title="View Rejection Note">
                    <i class="fas fa-exclamation-triangle"></i> NOTE!
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
?>