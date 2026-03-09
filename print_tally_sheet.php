<?php
session_start();
require_once 'config.php';

// 1. SECURITY
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Tournament Manager') {
    die("Access Denied");
}

if (!isset($_GET['category_id'])) {
    die("Invalid Request");
}

$category_id = (int)$_GET['category_id'];
$user_id = $_SESSION['user_id'];

// 2. FETCH EVENT DETAILS (Updated to fetch Medal Counts)
$sql = "SELECT 
            g.game_name, 
            ge.event_name, 
            c.category_name, 
            c.event_date, 
            c.event_time, 
            c.venue,
            c.gold_count,
            c.silver_count,
            c.bronze_count,
            u.full_name as manager_name
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN event_manager_assignments ema ON ge.event_id = ema.event_id
        LEFT JOIN users u ON ema.user_id = u.id
        WHERE c.category_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $category_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) die("Event not found.");

// --- Display Logic ---
$date_display = ($data['event_date']) ? date('F d, Y', strtotime($data['event_date'])) : '';
$time_display = ($data['event_time']) ? date('h:i A', strtotime($data['event_time'])) : '';
$venue_display = ($data['venue']) ? htmlspecialchars($data['venue']) : '';

// Pre-fill counts if they exist (for reprint), otherwise empty for handwriting
$gold_cnt = ($data['gold_count'] > 0) ? $data['gold_count'] : '';
$silver_cnt = ($data['silver_count'] > 0) ? $data['silver_count'] : '';
$bronze_cnt = ($data['bronze_count'] > 0) ? $data['bronze_count'] : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Tally Sheet - <?= htmlspecialchars($data['event_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #555;
            margin: 0;
            padding: 20px;
            color: #000;
        }
        .sheet-container {
            background: white;
            width: 210mm; 
            height: 297mm; 
            margin: 0 auto;
            padding: 30px 40px;
            box-sizing: border-box;
            box-shadow: 0 0 15px rgba(0,0,0,0.5);
            position: relative;
            display: flex;
            flex-direction: column;
        }
        
        /* HEADER */
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header-top {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        .logo { width: 70px; height: 70px; object-fit: contain; }
        .org-name { font-size: 14pt; font-weight: 800; text-transform: uppercase; }
        .org-sub { font-size: 10pt; font-weight: 400; color: #444; }
        .sheet-title { font-size: 18pt; font-weight: 800; text-transform: uppercase; margin-top: 10px; }

        /* INFO GRID */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 30px;
            margin-bottom: 25px;
            border: 1px solid #333;
            padding: 15px;
            background: #fdfdfd;
        }
        .info-item {
            font-size: 11pt;
            border-bottom: 1px dotted #ccc;
            padding-bottom: 3px;
            display: flex;
        }
        .info-label { font-weight: 700; width: 160px; flex-shrink: 0; font-size: 10pt; color: #333; }
        .info-value { font-weight: 600; color: #000; flex-grow: 1; }

        /* WINNERS TABLE */
        .winners-section { margin-bottom: auto; }
        .section-header {
            font-size: 12pt; font-weight: 800; text-transform: uppercase;
            margin-bottom: 10px; background: #eee; padding: 5px 10px; border-left: 5px solid #000;
        }
        
        .winners-table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .winners-table th, .winners-table td {
            border: 1px solid #000; padding: 12px 10px; text-align: center; /* Centered by default */
        }
        .winners-table th { background-color: #f4f4f4; text-transform: uppercase; font-size: 9pt; font-weight: 800; }
        .winners-table td.text-left { text-align: left; }
        
        .rank-col { font-weight: 800; width: 80px; font-size: 11pt; }
        .input-row { height: 60px; } /* Space for handwriting */
        
        /* SIGNATURES */
        .signatures {
            display: flex; justify-content: space-between; margin-top: 30px; margin-bottom: 20px;
        }
        .sig-block { width: 40%; text-align: center; }
        .sig-line { border-bottom: 1px solid #000; margin-bottom: 8px; height: 40px; }
        .sig-name { font-weight: 700; text-transform: uppercase; font-size: 11pt; }
        .sig-role { font-size: 9pt; color: #555; text-transform: uppercase; }

        /* FOOTER & BUTTON */
        .sheet-footer { border-top: 1px solid #ccc; padding-top: 10px; font-size: 8pt; color: #777; text-align: center; display: flex; justify-content: space-between; }
        .btn-print {
            position: fixed; top: 20px; right: 20px; background: #2563eb; color: white; border: none;
            padding: 12px 24px; cursor: pointer; border-radius: 8px; font-weight: 600; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.2); transition: all 0.2s; z-index: 9999;
        }
        .btn-print:hover { background: #1d4ed8; transform: translateY(-2px); }

        @media print {
            body { background: none; padding: 0; margin: 0; }
            .sheet-container { width: 100%; height: 100%; box-shadow: none; margin: 0; padding: 20px 30px; page-break-inside: avoid; }
            .no-print { display: none !important; }
            @page { margin: 0; size: A4; }
        }
    </style>
</head>
<body>

    <button onclick="window.print()" class="btn-print no-print">🖨️ Print Tally Sheet</button>

    <div class="sheet-container">
        <div class="header">
            <div class="header-top">
                <img src="imageslogo.png" alt="Logo" class="logo">
                <div class="org-details">
                    <div class="org-name">PIT Sports Tallying System</div>
                    <div class="org-sub">Official Tournament Documentation</div>
                </div>
            </div>
            <div class="sheet-title">Official Event Tally Sheet</div>
        </div>

        <div class="info-grid">
            <div class="info-item"><span class="info-label">GAME (L1):</span> <span class="info-value"><?= htmlspecialchars($data['game_name']) ?></span></div>
            <div class="info-item"><span class="info-label">DATE:</span> <span class="info-value"><?= $date_display ?></span></div>
            
            <div class="info-item"><span class="info-label">EVENT (L2):</span> <span class="info-value"><?= htmlspecialchars($data['event_name']) ?></span></div>
            <div class="info-item"><span class="info-label">TIME:</span> <span class="info-value"><?= $time_display ?></span></div>
            
            <div class="info-item"><span class="info-label">CATEGORY / DIVISION:</span> <span class="info-value"><?= htmlspecialchars($data['category_name']) ?></span></div>
            <div class="info-item"><span class="info-label">VENUE:</span> <span class="info-value"><?= $venue_display ?></span></div>
        </div>

        <div class="winners-section">
            <div class="section-header">Official Results</div>
            <p style="font-size: 10pt; font-style: italic; color: #555; margin-bottom: 10px;">
                Note: Please ensure all results, medal counts, and remarks are accurate and clearly written.
            </p>
            
            <table class="winners-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">RANK</th>
                        <th style="width: 45%;">WINNING TEAM / COLLEGE</th>
                        <th style="width: 15%;">MEDALS COUNTS</th>
                        <th style="width: 25%;">SCORE / TIME/ REMARKS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="input-row">
                        <td class="rank-col">🥇 GOLD</td>
                        <td class="text-left"></td>
                        <td style="font-weight:bold; font-size:12pt;"><?= $gold_cnt ?></td>
                        <td class="text-left"></td>
                    </tr>
                    <tr class="input-row">
                        <td class="rank-col">🥈 SILVER</td>
                        <td class="text-left"></td>
                        <td style="font-weight:bold; font-size:12pt;"><?= $silver_cnt ?></td>
                        <td class="text-left"></td>
                    </tr>
                    <tr class="input-row">
                        <td class="rank-col">🥉 BRONZE</td>
                        <td class="text-left"></td>
                        <td style="font-weight:bold; font-size:12pt;"><?= $bronze_cnt ?></td>
                        <td class="text-left"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="signatures">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-name">__________________________</div>
                <div class="sig-role">Official Referee / Umpire</div>
            </div>

            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-name"><?= htmlspecialchars($data['manager_name'] ?? '__________________________') ?></div>
                <div class="sig-role">Tournament Manager (Verified By)</div>
            </div>
        </div>

        <div class="sheet-footer">
            <span>Generated by SmartScore System</span>
            <span>Date Generated: <?= date('Y-m-d H:i:s') ?></span>
            
        </div>
    </div>

</body>
</html>