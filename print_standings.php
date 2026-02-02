<?php
require_once 'config.php'; 

// --- FETCH DATA ---
$sql = "SELECT 
            C.college_name, C.logo_url, C.college_code, C.unit_color, 
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
        GROUP BY C.college_id, C.college_name, C.logo_url, C.college_code, C.unit_color
        ORDER BY gold DESC, silver DESC, bronze DESC, C.college_name ASC";

$result = $conn->query($sql);
$medal_tally = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$default_logo = 'images/default_avatar.png'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Medal Standings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Times+New+Roman:wght@400;700&family=Arial:wght@400;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            background: #525659; /* Gray background for screen view */
            font-family: 'Times New Roman', serif; /* Formal Font */
        }
        
        /* THE PAPER SHEET */
        .page {
            background: white;
            width: 210mm; /* A4 Width */
            min-height: 297mm; /* A4 Height */
            margin: 30px auto;
            padding: 20mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.3);
            position: relative;
        }

        /* 1. LETTERHEAD */
        .letterhead {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid black;
            padding-bottom: 10px;
        }
        .lh-logos { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .lh-logo { width: 80px; height: 80px; object-fit: contain; }
        .lh-text h4 { margin: 0; font-family: 'Arial', sans-serif; font-weight: bold; text-transform: uppercase; font-size: 14pt; }
        .lh-text h5 { margin: 5px 0; font-size: 12pt; font-weight: normal; }
        .lh-text p { margin: 0; font-size: 10pt; color: #555; }

        /* 2. REPORT TITLE */
        .report-title {
            text-align: center;
            text-transform: uppercase;
            font-weight: bold;
            font-family: 'Arial', sans-serif;
            font-size: 16pt;
            margin: 20px 0;
            text-decoration: underline;
        }
        .report-meta {
            text-align: right;
            font-size: 10pt;
            margin-bottom: 10px;
            font-style: italic;
        }

        /* 3. FORMAL TABLE */
        .table-formal {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
            font-family: 'Arial', sans-serif;
        }
        .table-formal th, .table-formal td {
            border: 1px solid black;
            padding: 8px 12px;
            text-align: center;
            vertical-align: middle;
        }
        .table-formal th {
            background-color: #f0f0f0 !important; /* Light gray header */
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10pt;
            -webkit-print-color-adjust: exact;
        }
        .table-formal td { font-size: 11pt; }
        .text-left { text-align: left !important; }
        
        /* Rank 1 Highlight */
        .rank-1 { background-color: rgba(255, 215, 0, 0.15) !important; -webkit-print-color-adjust: exact; }

        /* 4. SIGNATURES */
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            page-break-inside: avoid;
        }
        .sig-block {
            width: 40%;
            text-align: center;
        }
        .sig-line {
            border-top: 1px solid black;
            margin-top: 40px;
            padding-top: 5px;
            font-weight: bold;
            text-transform: uppercase;
        }

        /* PRINT SETTINGS */
        @media print {
            body { background: white; }
            .page { margin: 0; box-shadow: none; border: none; width: 100%; padding: 0; }
            .no-print { display: none !important; }
            @page { margin: 20mm; size: A4 portrait; }
        }
    </style>
</head>
<body>

    <div class="no-print text-center p-3 bg-dark text-white mb-4">
        <span>Print Preview Mode</span>
        <button onclick="window.print()" class="btn btn-primary btn-sm ms-3 fw-bold">🖨 Print Official Report</button>
        <button onclick="window.close()" class="btn btn-secondary btn-sm ms-2">Close</button>
    </div>

    <div class="page">
        
        <div class="letterhead">
            <div class="lh-logos">
                <img src="imageslogo.png" alt="PIT Logo" class="lh-logo">
                <div class="lh-text">
                    <h4>Palompon Institute of Technology</h4>
                    <h5>Office of the Sports Director</h5>
                    <p>Palompon, Leyte</p>
                </div>
                <img src="images/COTE.png" alt="Event Logo" class="lh-logo">
            </div>
        </div>

        <h2 class="report-title">Official Medal Standing</h2>
        <div class="report-meta">
            Generated on: <?= date("F d, Y - h:i A"); ?>
        </div>

        <table class="table-formal">
            <thead>
                <tr>
                    <th style="width: 10%;">Rank</th>
                    <th style="width: 45%;">College</th>
                    <th style="width: 15%;">Gold</th>
                    <th style="width: 15%;">Silver</th>
                    <th style="width: 15%;">Bronze</th>
                    <th style="width: 15%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rank = 1;
                foreach($medal_tally as $row): 
                    $isChampion = ($rank == 1) ? 'rank-1' : '';
                ?>
                <tr class="<?= $isChampion ?>">
                    <td><?= $rank ?></td>
                    <td class="text-left">
                        <strong><?= htmlspecialchars($row['college_code']) ?></strong><br>
                        <small style="color:#555;"><?= htmlspecialchars($row['college_name']) ?></small>
                    </td>
                    <td><?= $row['gold'] ?></td>
                    <td><?= $row['silver'] ?></td>
                    <td><?= $row['bronze'] ?></td>
                    <td style="font-weight:bold;"><?= $row['total'] ?></td>
                </tr>
                <?php $rank++; endforeach; ?>
                
                <?php if(empty($medal_tally)): ?>
                    <tr><td colspan="6">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="signatures">
            <div class="sig-block">
                <p>Prepared by:</p>
                <div class="sig-line">JAYVEE BAAY</div>
                <small>System Administrator</small>
            </div>
            
            <div class="sig-block">
                <p>Certified Correct:</p>
                <div class="sig-line">SPORTS DIRECTOR NAME</div>
                <small>Sports Director</small>
            </div>
        </div>

        <div class="signatures" style="margin-top: 30px; justify-content: center;">
             <div class="sig-block">
                <p>Noted by:</p>
                <div class="sig-line">DR. COLLEGE PRESIDENT</div>
                <small>College President / Chairman</small>
            </div>
        </div>

    </div>

</body>
</html>