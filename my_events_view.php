<?php

/**
 * ==================================================================
 * my_events_view.php
 * * This file contains all presentation logic for 'my_events.php'.
 * It is responsible for rendering the HTML for the assigned events list.
 * ==================================================================
 */

/**
 * Main function to render the entire list of assigned events.
 *
 * @param array $managed_data The structured array of games, events, and categories.
 * @param array $college_map  An associative array mapping college_id to college_name.
 */
function render_event_list($managed_data, $college_map)
{
    // Check if any events are assigned
    if (empty($managed_data)) {
        echo '<div class="card shadow-sm">';
        echo '  <div class="card-body text-center p-5">';
        echo '    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>';
        echo '    <h5 class="card-title">No Events Assigned</h5>';
        echo '    <p class="text-muted">You do not have any events assigned to you at this time.</p>';
        echo '  </div>';
        echo '</div>';
        return; // Stop execution
    }

    // Loop through each Game (e.g., "Athletics", "Racket games")
    foreach ($managed_data as $game_name => $events) {
        echo '<div class="mb-5">';
        echo '  <h2 class="game-heading mb-3">' . htmlspecialchars($game_name) . '</h2>';

        // Loop through each Event in that Game (e.g., "Throws and Jumps")
        foreach ($events as $event) {
            $event_id = (int)$event['event_id'];
            $event_name = htmlspecialchars(strtoupper($event['event_name']));

            echo '<div class="card shadow-sm mb-4">';
            echo '  <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">';
            echo '        <h5 class="mb-0 fw-bold">' . $event_name . '</h5>';
            echo '    <button class="btn btn-primary btn-sm" 
                            data-bs-toggle="modal" 
                            data-bs-target="#categoryModal" 
                            data-action="add"
                            data-event-id="' . $event_id . '" 
                            data-event-name="' . $event_name . '">';
            echo '      <i class="fas fa-plus me-1"></i> Add Category';
            echo '    </button>';
            echo '  </div>';

            // Check if this event has any categories
            if (empty($event['categories'])) {
                echo '<div class="card-body text-center p-4">';
                echo '  <p class="text-muted mb-0">No categories have been added to this event yet.</p>';
                echo '</div>';
            } else {
                // This event has categories, render the table
                echo '<div class="table-responsive">';
                echo '  <table class="table table-hover align-middle mb-0" style="font-size: 0.95rem;">';
                echo '    <thead class="table-light">';
                echo '      <tr>';
                echo '        <th scope="col" style="min-width: 150px;">Category</th>';
                echo '        <th scope="col" style="min-width: 100px;">Type</th>';
                echo '        <th scope="col" style="min-width: 120px;">Status</th>';
                echo '        <th scope="col" style="min-width: 250px;">Approved Winners (Medal Count)</th>';
                
                // --- FIX APPLIED HERE ---
                echo '        <th scope="col" class="text-end" style="min-width: 240px;">Actions</th>';
                
                echo '      </tr>';
                echo '    </thead>';
                echo '    <tbody>';

                // Loop through each Category for this Event
                foreach ($event['categories'] as $category) {
                    $category_status = $category['status'];
                    
                    $table_row_class = '';
                    if ($category_status === 'Cancelled' || $category_status === 'Results Rejected') {
                        $table_row_class = 'table-danger-light';
                    }

                    echo '  <tr class="' . $table_row_class . '">';
                    echo '    <td class="fw-bold">' . htmlspecialchars($category['category_name']) . '</td>';
                    echo '    <td>' . htmlspecialchars(ucfirst($category['category_type'])) . '</td>';
                    
                    echo '    <td>' . get_status_badge($category['status'], $category['notes'], $category['category_id'], $category['category_name']) . '</td>';
                    
                    echo '    <td>' . render_winner_list($category, $college_map) . '</td>';
                    
                    // --- FIX APPLIED HERE ---
                    echo '    <td class="text-end">' . render_category_actions($category, $event_id, $event_name) . '</td>';
                    
                    echo '  </tr>';
                }

                echo '    </tbody>';
                echo '  </table>';
                echo '</div>'; // end .table-responsive
            }
            echo '</div>'; // end .card
        }
        echo '</div>'; // end .mb-5 (game wrapper)
    }
}

/**
 * Generates the new action button group based on category status and type.
 *
 * @param array $category   The category data row.
 * @param int   $event_id   The parent event's ID.
 * @param string $event_name The parent event's name.
 * @return string HTML for the button group.
 */
function render_category_actions($category, $event_id, $event_name)
{
    $category_id = (int)$category['category_id'];
    $category_name_safe = htmlspecialchars($category['category_name']);
    $event_name_safe = htmlspecialchars($event_name);
    $status = $category['status'];
    $category_type = $category['category_type'];
    $notes = $category['notes']; // Keep this here for the check, just in case

    // --- Data attributes for Modals ---
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

    // --- Define Button States ---
    $primary_btn_text = 'Manage';
    $primary_btn_class = 'btn-primary';
    $primary_btn_icon = 'fa-cog';
    $primary_btn_href = '#';
    $primary_btn_attrs = ''; // Extra attributes for modal triggers
    $primary_btn_disabled = false;


    // --- LOGIC FOR DIFFERENT CATEGORY TYPES ---
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
            $primary_btn_text = 'View Results';
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
            $primary_btn_text = 'View Results';
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

    // --- Edit Button (Universal Logic) ---
    $edit_btn_disabled = in_array($status, ['Ongoing', 'Completed', 'Results Approved', 'Results Submitted']);
    $edit_tooltip = $edit_btn_disabled ? "data-bs-toggle='tooltip' title='Cannot edit an event that is in-progress or completed'" : '';

    // --- Delete Button (Universal Logic) ---
    $delete_btn_disabled = !in_array($status, ['Upcoming', 'Cancelled']);
    $delete_tooltip = $delete_btn_disabled ? "data-BStoggle='tooltip' title='Can only delete &quot;Upcoming&quot; or &quot;Cancelled&quot; events'" : '';

    // --- Build HTML Output ---
    
    // --- FIX APPLIED HERE ---
    // 1. Replaced 'justify-content-between' with 'justify-content-end'
    // 2. Added 'gap-1' to create the small space
    // 3. Removed 'style="width: 100%;"'
    //
    $html = '<div class="d-flex justify-content-end align-items-center gap-1" role="group" aria-label="Category Actions">';

    // Button 1: Primary Action
    if ($primary_btn_disabled) {
        $html .= "<a href='#' class='btn btn-sm {$primary_btn_class} disabled' role='button' aria-disabled='true' style='white-space: nowrap;'>";
        $html .= "  <i class='fas {$primary_btn_icon} me-1'></i> " . htmlspecialchars($primary_btn_text);
        $html .= "</a>";
    } elseif (!empty($primary_btn_attrs)) {
        $html .= "<button type='button' class='btn btn-sm {$primary_btn_class}' {$primary_btn_attrs} style='white-space: nowrap;'>";
        $html .= "  <i class='fas {$primary_btn_icon} me-1'></i> " . htmlspecialchars($primary_btn_text);
        $html .= "</button>";
    } else {
        $html .= "<a href='{$primary_btn_href}' class='btn btn-sm {$primary_btn_class}' style='white-space: nowrap;'>";
        $html .= "  <i class='fas {$primary_btn_icon} me-1'></i> " . htmlspecialchars($primary_btn_text);
        $html .= "</a>";
    }

    // --- FIX APPLIED HERE ---
    // 2. Removed the extra wrapper <div> that was here
    //

    // Button 2: Edit
    $html .= "<button type='button' 
                        class='btn btn-sm btn-outline-secondary' 
                        {$edit_data_attrs} 
                        " . ($edit_btn_disabled ? 'disabled' : '') . "
                        {$edit_tooltip}>";
    $html .= "  <i class='fas fa-edit'></i>";
    $html .= "</button>";

    // Button 3: Delete
    $html .= "<button type='button' 
                        class='btn btn-sm btn-outline-danger' 
                        {$delete_data_attrs} 
                        " . ($delete_btn_disabled ? 'disabled' : '') . "
                        {$delete_tooltip}>";
    $html .= "  <i class='fas fa-trash-alt'></i>";
    $html .= "</button>";

    // --- FIX APPLIED HERE ---
    // 3. Removed the closing </div> for the extra wrapper
    //
    
    $html .= '</div>'; // Close main d-flex container

    return $html;
}

/**
 * Renders the winner list for a category.
 *
 * @param array $category  The category data row.
 * @param array $college_map An associative array mapping college_id to college_name.
 * @return string HTML for the winner list, or a placeholder.
 */
function render_winner_list($category, $college_map)
{
    $winners_html = []; // Use an array to build HTML strings
    $status = $category['status']; // Get the status once

    if ($status === 'Completed' || $status === 'Results Approved') {
        if (!empty($category['gold_winner_college_id'])) {
            $winners_html[] = '<div class="winner-item-line gold"><i class="fas fa-medal winner-icon"></i> '
                . '<strong>' . htmlspecialchars($college_map[$category['gold_winner_college_id']] ?? 'N/A') . '</strong>'
                . '&nbsp;(' . (int)$category['gold_count'] . ')</div>';
        }
        if (!empty($category['silver_winner_college_id'])) {
            $winners_html[] = '<div class="winner-item-line silver"><i class="fas fa-medal winner-icon"></i> '
                . '<strong>' . htmlspecialchars($college_map[$category['silver_winner_college_id']] ?? 'N/A') . '</strong>'
                . '&nbsp;(' . (int)$category['silver_count'] . ')</div>';
        }
        if (!empty($category['bronze_winner_college_id'])) {
            $winners_html[] = '<div class="winner-item-line bronze"><i class="fas fa-medal winner-icon"></i> '
                . '<strong>' . htmlspecialchars($college_map[$category['bronze_winner_college_id']] ?? 'N/A') . '</strong>'
                . '&nbsp;(' . (int)$category['bronze_count'] . ')</div>';
        }
    }

    if (!empty($winners_html)) {
        return implode('', $winners_html);
    }

    switch ($status) {
        case 'Upcoming':
            return '<small class="text-muted fst-italic">Event has not started.</small>';
            
        case 'Ongoing':
            return '<small class="text-muted fst-italic">Event is in progress...</small>';
            
        case 'Completed (Pending Results)':
            return '<small class="text-muted fst-italic">Awaiting admin approval...</small>';

        case 'Results Rejected':
            return '<small class="text-danger fst-italic">Results were rejected.</small>';
            
        case 'Results Submitted':
            return '<small class="text-muted fst-italic">Awaiting admin approval...</small>';
            
        case 'Completed':
        case 'Results Approved':
            return '<small class="text-muted fst-italic">No winners recorded.</small>';
            
        case 'Postponed':
            return '<small class="text-muted fst-italic">Event is postponed.</small>';
            
        case 'Cancelled':
            return '<small classD="text-muted fst-italic">Event was cancelled.</small>';
            
        default:
            return '<small class="text-muted fst-italic">No results available.</small>';
    }
}

/**
 * Returns a Bootstrap badge based on the category status.
 *
 * @param string $status The status text.
 * @param string|null $notes Optional notes, used for rejection.
 * @param int|null $category_id
 * @param string|null $category_name
 * @return string HTML for the badge.
 */
function get_status_badge($status, $notes = null, $category_id = null, $category_name = null)
{
    $class = 'text-bg-secondary'; // Default
    $display_status = $status;
    
    switch ($status) {
        case 'Upcoming':
            $class = 'text-bg-primary';
            break;
        case 'Ongoing':
            $class = 'text-bg-success';
            break;
        case 'Completed (Pending Results)':
            $class = 'text-bg-warning';
            $display_status = 'Pending Approval'; // Shorter text
            break;
        case 'Results Submitted':
            $class = 'text-bg-warning';
            $display_status = 'Submitted'; // Shorter text
            break;
        case 'Completed':
        case 'Results Approved':
            $class = 'text-bg-info';
            $display_status = 'Completed'; // Standardize
            break;
        case 'Postponed':
            $class = 'text-bg-dark';
            break;
        
        case 'Results Rejected':
            $class = 'text-bg-danger';
            $badge = '<span class="badge ' . $class . ' text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.5px;">' . htmlspecialchars($status) . '</span>';
            
            $note_button_html = '';
            if (!empty($notes)) {
                $note_data_attrs = "data-bs-toggle='modal' 
                                    data-bs-target='#noteModal' 
                                    data-category-name='" . htmlspecialchars($category_name, ENT_QUOTES) . "' 
                                    data-note='" . htmlspecialchars($notes, ENT_QUOTES) . "'";
                
                $note_button_html = '
                    <button type="button" 
                            class="btn btn-sm btn-outline-danger mt-1 py-0 px-1" 
                            ' . $note_data_attrs . ' 
                            data-bs-toggle="tooltip" title="View Rejection Note"
                            style="font-size: 2 rem; line-height: 1">
                        <i class="fas fa-exclamation-triangle"> NOTE!</i>
                    </button>';
            }
            
            return '<div>' . $badge . $note_button_html . '</div>';

        case 'Cancelled':
            $class = 'text-bg-danger';
            break;
    }
    
    return '<span class="badge ' . $class . ' text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.5px;">' . htmlspecialchars($display_status) . '</span>';
}
?>