<?php
// fetch_teams_api.php
require_once 'config.php';

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

$colleges_data = [];

// This is the EXACT same query from college_team.php
$sql = "
    SELECT
        C.college_id,
        C.college_name,
        C.college_code,
        C.team_manager,
        C.slogan,
        C.logo_url,
        C.unit_color,
        COALESCE(Medals.GoldCount, 0) AS GoldCount,
        COALESCE(Medals.SilverCount, 0) AS SilverCount,
        COALESCE(Medals.BronzeCount, 0) AS BronzeCount,
        (COALESCE(Medals.GoldCount, 0) + COALESCE(Medals.SilverCount, 0) + COALESCE(Medals.BronzeCount, 0)) AS TotalMedals
    FROM
        colleges C
    LEFT JOIN (
        SELECT
            college_id,
            SUM(GoldCount) AS GoldCount,
            SUM(SilverCount) AS SilverCount,
            SUM(BronzeCount) AS BronzeCount
        FROM (
            SELECT gold_winner_college_id AS college_id, SUM(gold_count) AS GoldCount, 0 AS SilverCount, 0 AS BronzeCount FROM categories WHERE status = 'Results Approved' AND gold_winner_college_id IS NOT NULL GROUP BY gold_winner_college_id
            UNION ALL
            SELECT silver_winner_college_id AS college_id, 0 AS GoldCount, SUM(silver_count) AS SilverCount, 0 AS BronzeCount FROM categories WHERE status = 'Results Approved' AND silver_winner_college_id IS NOT NULL GROUP BY silver_winner_college_id
            UNION ALL
            SELECT bronze_winner_college_id AS college_id, 0 AS GoldCount, 0 AS SilverCount, SUM(bronze_count) AS BronzeCount FROM categories WHERE status = 'Results Approved' AND bronze_winner_college_id IS NOT NULL GROUP BY bronze_winner_college_id
        ) AS MedalCounts
        WHERE college_id IS NOT NULL
        GROUP BY college_id
    ) AS Medals ON C.college_id = Medals.college_id
    ORDER BY
        GoldCount DESC, SilverCount DESC, BronzeCount DESC, C.college_name ASC;
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Sanitize data for JSON
        $row['slogan'] = $row['slogan'] ?? 'No slogan.';
        $row['logo_url'] = $row['logo_url'] ?? 'images/default_avatar.png';
        $row['unit_color'] = $row['unit_color'] ?? '#cccccc';
        $colleges_data[] = $row;
    }
}

echo json_encode($colleges_data);
$conn->close();
?>