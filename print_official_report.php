<?php
session_start();
require_once 'config.php'; 

// 1. SECURITY CHECK
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die("Access Denied: You must be logged in to print reports.");
}

// 2. FETCH DATA FOR PAGE 1 (MEDAL SUMMARY)
// Sort default: Gold -> Silver -> Bronze
$sql_medals = "SELECT 
            C.college_name, C.college_code, C.logo_url,
            SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) AS gold,
            SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) AS silver,
            SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END) AS bronze,
            (SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) +
             SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) +
             SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END)) AS total
        FROM colleges C
        LEFT JOIN categories Cat ON (
            C.college_id = Cat.gold_winner_college_id OR 
            C.college_id = Cat.silver_winner_college_id OR 
            C.college_id = Cat.bronze_winner_college_id
        ) AND Cat.status = 'Results Approved'
        GROUP BY C.college_id, C.college_name, C.college_code, C.logo_url
        ORDER BY gold DESC, silver DESC, bronze DESC, C.college_name ASC";

$result_medals = $conn->query($sql_medals);
$medal_data = $result_medals ? $result_medals->fetch_all(MYSQLI_ASSOC) : [];

// 3. FETCH DATA FOR PAGE 2 (DETAILED EVENTS)
$sql_events = "SELECT 
            g.game_name, ge.event_name, c.category_name, c.division_name, 
            c.event_date, c.event_time,
            col_gold.college_code AS gold_winner,
            col_silver.college_code AS silver_winner,
            col_bronze.college_code AS bronze_winner,
            c.gold_count, c.silver_count, c.bronze_count
        FROM categories c
        LEFT JOIN game_events ge ON c.event_id = ge.event_id
        LEFT JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN colleges col_gold ON c.gold_winner_college_id = col_gold.college_id
        LEFT JOIN colleges col_silver ON c.silver_winner_college_id = col_silver.college_id
        LEFT JOIN colleges col_bronze ON c.bronze_winner_college_id = col_bronze.college_id
        WHERE c.status = 'Results Approved'
        ORDER BY g.game_name ASC, ge.event_name ASC, c.category_name ASC";

$result_events = $conn->query($sql_events);
$event_data = $result_events ? $result_events->fetch_all(MYSQLI_ASSOC) : [];

// 4. USER INFO
// 4. USER INFO (Fetch Real Name from Database)
$prepared_by = 'System Administrator'; // Default fallback

if (isset($_SESSION['user_id'])) {
    // Query the database for the specific user's full name
    $stmt_user = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $_SESSION['user_id']);
    $stmt_user->execute();
    $res_user = $stmt_user->get_result();
    
    if ($row_user = $res_user->fetch_assoc()) {
        // Use full_name if it exists and isn't empty
        if (!empty($row_user['full_name'])) {
            $prepared_by = $row_user['full_name'];
        } else {
            // Otherwise, use the username
            $prepared_by = $row_user['username'];
        }
    }
    $stmt_user->close();
}

function formatDateTime($date, $time) {
    if (!$date) return '-';
    $d = date("M d, Y", strtotime($date));
    $t = $time ? date("h:i A", strtotime($time)) : '';
    return $d . ' ' . $t;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Tournament Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        /* ============================================
           BASE SETTINGS & PAGE LAYOUT
           ============================================ */
        * { 
            box-sizing: border-box; 
            margin: 0;
            padding: 0;
        }
        
        body { 
            background: #e8e8e8; 
            margin: 0; 
            padding: 15px;
            font-family: 'Roboto', Arial, sans-serif;
            color: #2c3e50;
            line-height: 1.5;
        }

        /* PAPER SHEET - Optimized for A4 */
        .page-container {
            background: white;
            width: 210mm; 
            min-height: 297mm;
            margin: 0 auto 20px auto;
            padding: 15mm 18mm;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
            position: relative;
        }

        /* ============================================
           LETTERHEAD & HEADER STYLING
           ============================================ */
        .letterhead { 
            text-align: center; 
            padding-bottom: 12px; 
            margin-bottom: 25px; 
        }
        
        .lh-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
        }
        
        .lh-logo { 
            width: 70px; 
            height: 70px; 
            object-fit: contain; 
        }
        
        .lh-text h3 { 
            margin: 0; 
            font-weight: 700; 
            font-size: 16pt; 
            text-transform: uppercase; 
            color: #1a252f;
            letter-spacing: 0.5px;
        }
        
        .lh-text h4 { 
            margin: 4px 0 0; 
            font-size: 11pt; 
            font-weight: 500; 
            color: #34495e;
        }
        
        .lh-text p {
            margin: 2px 0 0;
            font-size: 9pt;
            color: #7f8c8d;
        }
        
        /* Report Title */
        .report-title { 
            text-align: center; 
            font-weight: 700; 
            font-size: 15pt; 
            margin: 20px 0; 
            text-transform: uppercase; 
            color: #2c3e50;
            letter-spacing: 1px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .report-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 3px;
            background: #3498db;
        }

        .meta-info {
            text-align: right;
            font-size: 9pt;
            color: #7f8c8d;
            margin-bottom: 20px;
            font-weight: 300;
        }

        /* ============================================
           PROFESSIONAL TABLE STYLING
           ============================================ */
        .formal-table {
            width: 100%; 
            border-collapse: collapse; 
            font-size: 10pt; 
            margin-bottom: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        /* Table Headers */
        .formal-table thead {
            background: linear-gradient(to bottom, #34495e 0%, #2c3e50 100%);
            color: white;
        }
        
        .formal-table th { 
            border: 1px solid #2c3e50; 
            padding: 12px 10px; 
            text-align: center; 
            font-weight: 600;
            font-size: 9.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        /* Table Data Cells */
        .formal-table td { 
            border: 1px solid #bdc3c7; 
            padding: 10px; 
            text-align: center;
            font-size: 10pt;
        }
        
        /* Text Alignment Helpers */
        .formal-table .text-left { 
            text-align: left; 
            padding-left: 12px; 
        }
        
        .formal-table .text-right { 
            text-align: right; 
            padding-right: 12px; 
        }
        
        /* Row Styling */
        .formal-table tbody tr {
            transition: background-color 0.2s;
        }
        
        .formal-table tbody tr:nth-child(odd) { 
            background-color: #ffffff;
        }
        
        .formal-table tbody tr:nth-child(even) { 
            background-color: #f8f9fa;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .formal-table tbody tr:hover {
            background-color: #e8f4f8 !important;
        }

        /* Special Column Styling */
        .rank-cell {
            font-weight: 600;
            color: #2c3e50;
            background-color: #ecf0f1 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .college-cell {
            font-weight: 500;
        }
        
        .college-code {
            font-weight: 700;
            color: #2c3e50;
            font-size: 10.5pt;
        }
        
        .college-name {
            color: #7f8c8d;
            font-size: 9pt;
            font-weight: 400;
        }
        
        /* Medal Count Styling */
        .medal-gold {
            background-color: #fff9e6 !important;
            font-weight: 600;
            color: #f39c12;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .medal-silver {
            background-color: #f5f5f5 !important;
            font-weight: 600;
            color: #95a5a6;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .medal-bronze {
            background-color: #fff4e6 !important;
            font-weight: 600;
            color: #d35400;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .total-cell {
            font-weight: 700;
            color: #2c3e50;
            font-size: 11pt;
            background-color: #e8f4f8 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Event Details Table Specific */
        .game-cell {
            font-weight: 600;
            color: #2c3e50;
            background-color: #ecf0f1 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .date-cell {
            font-size: 9pt;
            color: #7f8c8d;
        }
        
        .winner-cell {
            font-weight: 500;
        }
        
        .winner-count {
            font-size: 8.5pt;
            color: #7f8c8d;
            font-weight: 400;
        }

        /* Empty State */
        .empty-row td {
            text-align: center;
            padding: 30px;
            color: #95a5a6;
            font-style: italic;
            font-size: 11pt;
        }

        /* ============================================
           SIGNATURE SECTION
           ============================================ */
        .signatures-wrapper { 
            margin-top: 60px; 
            page-break-inside: avoid; 
        }
        
        .sig-row { 
            display: flex; 
            justify-content: space-between; 
            margin-bottom: 50px; 
        }
        
        .sig-block { 
            width: 45%; 
        }
        
        .sig-label {
            font-size: 10pt;
            color: #7f8c8d;
            margin-bottom: 50px;
            font-weight: 400;
        }
        
        .sig-line { 
            border-top: 2px solid #2c3e50; 
            padding-top: 8px; 
            font-weight: 700; 
            text-transform: uppercase; 
            text-align: center;
            font-size: 11pt;
            color: #2c3e50;
        }
        
        .sig-role { 
            font-size: 9.5pt; 
            font-style: italic; 
            text-align: center;
            color: #7f8c8d;
            margin-top: 4px;
        }

        /* ============================================
           PRINT CONTROLS (SCREEN ONLY)
           ============================================ */
        .print-controls { 
            position: fixed; 
            top: 20px; 
            right: 20px; 
            display: flex; 
            flex-direction: column; 
            gap: 10px;
            z-index: 1000;
        }
        
        .btn { 
            padding: 12px 24px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            color: white; 
            font-weight: 600;
            font-size: 10pt;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }
        
        .btn-print { 
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        }
        
        .btn-close { 
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
        }

        /* Page Break */
        .page-break { 
            page-break-before: always; 
        }

        /* Footer Text */
        .report-footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #bdc3c7;
            font-size: 9pt;
            color: #95a5a6;
            font-style: italic;
        }

        /* Attachment Header */
        .attachment-header {
            text-align: center;
            margin-bottom: 25px;
            font-style: italic;
            color: #7f8c8d;
            font-size: 10pt;
        }

        /* ============================================
           PRINT MEDIA QUERIES
           ============================================ */
        @media print {
            body { 
                background: none; 
                padding: 0; 
            }
            
            .page-container { 
                width: 100%; 
                margin: 0; 
                padding: 0; 
                box-shadow: none; 
            }
            
            .print-controls { 
                display: none; 
            }
            
            /* Ensure colors print correctly */
            .formal-table thead,
            .formal-table th,
            .rank-cell,
            .medal-gold,
            .medal-silver,
            .medal-bronze,
            .total-cell,
            .game-cell {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            /* Page setup */
            @page { 
                margin: 15mm 18mm; 
                size: A4 portrait; 
            }
            
            /* Prevent breaks inside important elements */
            .letterhead,
            .signatures-wrapper,
            .sig-row {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <!-- PRINT CONTROLS (SCREEN ONLY) -->
    <div class="print-controls">
        <button onclick="window.print()" class="btn btn-print">
            <i class="fas fa-print"></i> Print Report
        </button>
        <button onclick="window.close()" class="btn btn-close">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <!-- ============================================
         PAGE 1: MEDAL TALLY SUMMARY
         ============================================ -->
    <div class="page-container">
        
        <!-- Letterhead -->
        <div class="letterhead">
            <div class="lh-header">
                <img src="images/PIT.png" alt="Institution Logo" class="lh-logo">
                <div class="lh-text">
                    <h1>Palompon Institute of Technology</h1>                   
                    <h2>Official Siglakas Results</h2>
                    <p>Palompon, Leyte</p>
                </div>
                <img src="images/COTE.png" alt="Event Logo" class="lh-logo">
            </div>
        </div>

        <!-- Report Title -->
        <div class="report-title">Official Medal Tally Summary</div>
        
        <!-- Generated Date -->
        <div class="meta-info">Generated: <?= date("F d, Y - h:i A") ?></div>

        <!-- Medal Tally Table -->
        <table class="formal-table">
            <thead>
                <tr>
                    <th style="width: 8%;">Rank</th>
                    <th style="width: 47%;" class="text-left">College / Unit</th>
                    <th style="width: 12%;">Gold</th>
                    <th style="width: 12%;">Silver</th>
                    <th style="width: 12%;">Bronze</th>
                    <th style="width: 9%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rank = 1; 
                if(empty($medal_data)): 
                ?>
                    <tr class="empty-row">
                        <td colspan="6">No medal records found.</td>
                    </tr>
                <?php 
                else:
                    foreach($medal_data as $row): 
                ?>
                <tr>
                    <td class="rank-cell"><?= $rank++ ?></td>
                    <td class="text-left college-cell">
                        <span class="college-code"><?= htmlspecialchars($row['college_code']) ?></span><br>
                        <span class="college-name"><?= htmlspecialchars($row['college_name']) ?></span>
                    </td>
                    <td class="medal-gold"><?= $row['gold'] ?></td>
                    <td class="medal-silver"><?= $row['silver'] ?></td>
                    <td class="medal-bronze"><?= $row['bronze'] ?></td>
                    <td class="total-cell"><?= $row['total'] ?></td>
                </tr>
                <?php 
                    endforeach;
                endif; 
                ?>
            </tbody>
        </table>

        <!-- Signatures -->
        <div class="signatures-wrapper">
            <div class="sig-row">
                <div class="sig-block">
                    <div class="sig-label">Prepared by:</div>
                    <div class="sig-line"><?= htmlspecialchars(strtoupper($prepared_by)) ?></div>
                    <div class="sig-role">SPORTS DIRECTOR</div>
                </div>
                <div class="sig-block">
                    <div class="sig-label">Certified Correct:</div>
                    <div class="sig-line">Dr. Christian Caben M. Larisma</div>
                    <div class="sig-role">Overall Manager</div>
                </div>
            </div>
            <div class="sig-row" style="justify-content: center;">
                <div class="sig-block">
                    <div class="sig-label">Noted by:</div>
                    <div class="sig-line">DR. Claudine L. Igot</div>
                    <div class="sig-role">Head of Siglakas Games</div>
                </div>
            </div>
        </div>

    </div>

    <!-- ============================================
         PAGE 2: DETAILED EVENT RESULTS
         ============================================ -->
    <div class="page-container page-break">

        <!-- Letterhead -->
        <div class="letterhead">
            <div class="lh-header">
                <img src="images/PIT.png" alt="Institution Logo" class="lh-logo">
                <div class="lh-text">
                    <h1>Palompon Institute of Technology</h1>                   
                    <h2>Official Siglakas Results</h2>
                    <p>Palompon, Leyte</p>
                </div>
                <img src="images/COTE.png" alt="Event Logo" class="lh-logo">
            </div>
        </div>
        
        <!-- Report Title -->
        <div class="report-title" style="font-size: 13pt;">Attachment A: Detailed Event Results</div>
        
        <!-- Subtitle -->
        <div class="attachment-header">Supporting details for the Official Medal Tally</div>

        <!-- Detailed Events Table -->
        <table class="formal-table">
            <thead>
                <tr>
                    <th style="width: 13%;">Game</th>
                    <th style="width: 14%;">Event</th>
                    <th style="width: 12%;">Category & Division</th>
                    <th style="width: 15%;">Date & Time</th>
                    <th style="width: 15%;">Gold</th>
                    <th style="width: 15%;">Silver</th>
                    <th style="width: 16%;">Bronze</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if(empty($event_data)): 
                ?>
                    <tr class="empty-row">
                        <td colspan="7">No completed events found.</td>
                    </tr>
                <?php 
                else:
                    foreach($event_data as $row): 
                ?>
                <tr>
                    <td class="game-cell">
                        <?= htmlspecialchars($row['game_name']) ?>
                    </td>
                    
                    <td class="text-left">
                        <?= htmlspecialchars($row['event_name']) ?>
                    </td>
                    
                    <td>
                        <?php if ($row['category_name'] !== 'Main Event'): ?>
                            <strong style="color: #2c3e50;"><?= htmlspecialchars($row['category_name']) ?></strong>
                            
                            <?php if (!empty($row['division_name'])): ?>
                                <br>
                                <span style="font-size: 0.85em; color: #555;">
                                    <?= htmlspecialchars($row['division_name']) ?>
                                </span>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <span style="color:#bdc3c7;">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="date-cell">
                        <?= formatDateTime($row['event_date'], $row['event_time']) ?>
                    </td>

                    <td class="winner-cell medal-gold">
                        <?php if($row['gold_winner']): ?>
                            <?= htmlspecialchars($row['gold_winner']) ?>
                            <?php if($row['gold_count'] > 0): ?>
                                <span class="winner-count">(<?= $row['gold_count'] ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#bdc3c7;">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="winner-cell medal-silver">
                        <?php if($row['silver_winner']): ?>
                            <?= htmlspecialchars($row['silver_winner']) ?>
                            <?php if($row['silver_count'] > 0): ?>
                                <span class="winner-count">(<?= $row['silver_count'] ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#bdc3c7;">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="winner-cell medal-bronze">
                        <?php if($row['bronze_winner']): ?>
                            <?= htmlspecialchars($row['bronze_winner']) ?>
                            <?php if($row['bronze_count'] > 0): ?>
                                <span class="winner-count">(<?= $row['bronze_count'] ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#bdc3c7;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php 
                    endforeach;
                endif; 
                ?>
            </tbody>
        </table>

        <!-- Signatures -->
        <div class="signatures-wrapper">
            <div class="sig-row">
                <div class="sig-block">
                    <div class="sig-label">Prepared by:</div>
                    <div class="sig-line"><?= htmlspecialchars(strtoupper($prepared_by)) ?></div>
                    <div class="sig-role">SPORTS DIRECTOR</div>
                </div>
                <div class="sig-block">
                    <div class="sig-label">Certified Correct:</div>
                    <div class="sig-line">Dr. Christian Caben M. Larisma</div>
                    <div class="sig-role">Overall Manager</div>
                </div>
            </div>
            <div class="sig-row" style="justify-content: center;">
                <div class="sig-block">
                    <div class="sig-label">Noted by:</div>
                    <div class="sig-line">DR. Claudine L. Igot</div>
                    <div class="sig-role">Head of Siglakas Games</div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="report-footer">— End of Report —</div>

    </div>

</body>
</html>