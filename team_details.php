<?php
session_start();

// Sample teams data (same structure as Manage_Team.php)
$teams_data = [
    [
        'id' => 1,
        'name' => 'College of Technology and Engineering',
        'badge' => 'COTE',
        'logo' => 'images/COTE.png',
        'description' => 'The College of Technology and Engineering is a formidable contender, known for its strategic and analytical prowess in various sports.',
        'points' => 150,
        'accentColor' => '#800000',
        'dean_name' => 'Dr. Maria Santos',
        'total_students' => 2500,
        'sports_events' => ['Volleyball', 'Basketball', 'Table Tennis'],
        'medals' => ['gold' => 5, 'silver' => 3, 'bronze' => 8],
        'upcoming_matches' => [
            ['opponent' => 'CAS', 'date' => 'Oct 20', 'venue' => 'Gymnasium'],
            ['opponent' => 'COMED', 'date' => 'Oct 22', 'venue' => 'Court A'],
        ],
    ],
    [
        'id' => 2,
        'name' => 'College of Arts and Sciences',
        'badge' => 'CAS',
        'logo' => 'images/CASlogo.png',
        'description' => 'The College of Arts and Sciences teams often excel in sports that require a high degree of planning and coordination, demonstrating strong intellectual and physical abilities.',
        'points' => 250,
        'accentColor' => '#FFFF00',
        'dean_name' => 'Engr. Robert Tan',
        'total_students' => 1800,
        'sports_events' => ['Chess', 'E-Sports', 'Table Tennis'],
        'medals' => ['gold' => 12, 'silver' => 7, 'bronze' => 2],
        'upcoming_matches' => [
            ['opponent' => 'COTE', 'date' => 'Oct 20', 'venue' => 'Gymnasium'],
            ['opponent' => 'PIT - TC', 'date' => 'Oct 23', 'venue' => 'Auditorium'],
        ],
    ],
    [
        'id' => 3,
        'name' => 'College of Maritime Education',
        'badge' => 'COMED',
        'logo' => 'images/COMED.png',
        'description' => 'Known for their discipline and resilience, the COMED teams are a force to be reckoned with, showcasing great teamwork in every competition.',
        'points' => 200,
        'accentColor' => '#008000',
        'dean_name' => 'Ms. Sofia Reyes',
        'total_students' => 1500,
        'sports_events' => ['Culinary Race', 'Swimming', 'Badminton'],
        'medals' => ['gold' => 8, 'silver' => 5, 'bronze' => 10],
        'upcoming_matches' => [
            ['opponent' => 'CTE', 'date' => 'Oct 21', 'venue' => 'Main Pool'],
            ['opponent' => 'COTE', 'date' => 'Oct 22', 'venue' => 'Court A'],
        ],
    ],
    [
        'id' => 4,
        'name' => 'College Teachers Education',
        'badge' => 'CTE',
        'logo' => 'images/CTE.png',
        'description' => 'The CTE department is a strong contender with well-rounded athletes participating across all sports, always bringing energy and enthusiasm to every match.',
        'points' => 180,
        'accentColor' => '#87CEEB',
        'dean_name' => 'Dr. Antonio Cruz',
        'total_students' => 2200,
        'sports_events' => ['Debate', 'Track and Field', 'Soccer'],
        'medals' => ['gold' => 6, 'silver' => 9, 'bronze' => 4],
        'upcoming_matches' => [
            ['opponent' => 'PIT - TC', 'date' => 'Oct 24', 'venue' => 'Field'],
            ['opponent' => 'CAS', 'date' => 'Oct 25', 'venue' => 'Auditorium'],
        ],
    ],
    [
        'id' => 5,
        'name' => 'Palompon Institute of Technology Tabango Campus',
        'badge' => 'PIT - TC',
        'logo' => 'images/PIT.png',
        'description' => 'PIT - TC teams are known for their discipline and competitive drive, aiming for the top spots in every event.',
        'points' => 220,
        'accentColor' => '#0000FF',
        'dean_name' => 'Dean Richard Lim',
        'total_students' => 2100,
        'sports_events' => ['Soccer', 'Stock Market Simulation', 'Volleyball'],
        'medals' => ['gold' => 9, 'silver' => 6, 'bronze' => 3],
        'upcoming_matches' => [
            ['opponent' => 'COMED', 'date' => 'Oct 21', 'venue' => 'Main Pool'],
            ['opponent' => 'CAS', 'date' => 'Oct 23', 'venue' => 'Auditorium'],
        ],
    ],
];

function get_dean_photo_url($badge) {
    $safeBadge = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$badge);
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'deans';
    $relBase = 'uploads/deans/';
    if (is_dir($dir)) {
        foreach (['jpg','jpeg','png','gif','webp'] as $ext) {
            $path = $dir . DIRECTORY_SEPARATOR . $safeBadge . '.' . $ext;
            if (file_exists($path)) { return $relBase . $safeBadge . '.' . $ext; }
        }
        $matches = glob($dir . DIRECTORY_SEPARATOR . $safeBadge . '.*');
        if ($matches && file_exists($matches[0])) {
            return $relBase . basename($matches[0]);
        }
    }
    return '';
}

$badge = isset($_GET['badge']) ? $_GET['badge'] : '';
$team = null;
foreach ($teams_data as $t) {
    if ($t['badge'] === $badge) { $team = $t; break; }
}

$deanPhotoUrl = $team ? get_dean_photo_url($team['badge']) : '';

if (!$team) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $team ? ($team['name'] . ' - Team Details') : 'Team Not Found' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background: #f5f7fb; }
        .container-narrow { max-width: 1100px; }
        .details-card { border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); background-color: #fff; padding: 20px; height: 100%; }
        .dean-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; }
        .medal-badge { font-size: 1.2rem; }
        .header-banner { background-color: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); padding: 20px; }
        .badge-pill { border-radius: 50rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="Tournament_Manager_page.php">PIT SPORTS TALLYING</a>
            <div class="ms-auto">
                <a href="Manage_Team.php" class="btn btn-outline-light btn-sm">Back to Admin</a>
            </div>
        </div>
    </nav>

    <div class="container container-narrow my-4">
        <?php if (!$team): ?>
            <div class="alert alert-danger">Team not found.</div>
        <?php else: ?>
            <div class="header-banner d-flex align-items-center gap-3 mb-4" style="border-left: 6px solid <?= htmlspecialchars($team['accentColor']) ?>;">
                <img src="<?= htmlspecialchars($team['logo']) ?>" alt="<?= htmlspecialchars($team['badge']) ?> Logo" style="width: 80px; height: 80px;" class="rounded-circle shadow-sm">
                <div>
                    <h3 class="mb-1"><?= htmlspecialchars($team['name']) ?></h3>
                    <div>
                        <span class="badge bg-secondary badge-pill me-2"><?= htmlspecialchars($team['badge']) ?></span>
                        <span class="text-muted"><?= htmlspecialchars($team['description']) ?></span>
                    </div>
                </div>
                <a href="Teams.php" class="btn btn-primary btn-sm ms-auto"><i class="fas fa-users me-2"></i>Public Teams View</a>
            </div>

            <div class="row g-4">
                <div class="col-md-6">
                    <div class="details-card">
                        <h5 class="mb-3"><i class="fas fa-graduation-cap me-2"></i>Dean</h5>
                        <div class="d-flex align-items-center gap-3">
                            <img src="<?= $deanPhotoUrl ? htmlspecialchars($deanPhotoUrl) : 'https://placehold.co/80x80/6c757d/ffffff?text=Dean' ?>" alt="Dean" class="dean-avatar">
                            <span class="fw-bold"><?= htmlspecialchars($team['dean_name']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="details-card text-center">
                        <h5 class="mb-3"><i class="fas fa-users me-2"></i>Total Students</h5>
                        <h2 class="display-5 fw-bold text-primary my-auto"><?= number_format($team['total_students']) ?></h2>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="details-card">
                        <h5 class="mb-3"><i class="fas fa-futbol me-2"></i>Sports Participation</h5>
                        <?php if (!empty($team['sports_events'])): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($team['sports_events'] as $sport): ?>
                                    <li class="list-group-item d-flex align-items-center p-2"><i class="fas fa-check-circle text-success me-2"></i><?= htmlspecialchars($sport) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="text-muted">No sports listed</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="details-card">
                        <h5 class="mb-3"><i class="fas fa-calendar-check me-2"></i>Upcoming Matches</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-muted">Opponent</th>
                                        <th class="text-muted">Date</th>
                                        <th class="text-muted">Venue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($team['upcoming_matches'])): ?>
                                        <?php foreach ($team['upcoming_matches'] as $m): ?>
                                            <tr>
                                                <td class="fw-bold"><?= htmlspecialchars($m['opponent']) ?></td>
                                                <td><?= htmlspecialchars($m['date']) ?></td>
                                                <td><?= htmlspecialchars($m['venue']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-muted">No upcoming matches</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="details-card text-center">
                        <h5 class="mb-3"><i class="fas fa-trophy me-2"></i>Total Medals</h5>
                        <div class="d-flex justify-content-center align-items-center gap-4 my-3">
                            <div class="text-center">
                                <span class="medal-badge">🥇</span><br>
                                <span class="fw-bold fs-4"><?= (int)$team['medals']['gold'] ?></span>
                            </div>
                            <div class="text-center">
                                <span class="medal-badge">🥈</span><br>
                                <span class="fw-bold fs-4"><?= (int)$team['medals']['silver'] ?></span>
                            </div>
                            <div class="text-center">
                                <span class="medal-badge">🥉</span><br>
                                <span class="fw-bold fs-4"><?= (int)$team['medals']['bronze'] ?></span>
                            </div>
                        </div>
                        <hr>
                        <h6 class="text-muted">Total: <span class="fw-bold text-dark fs-5"><?= (int)$team['medals']['gold'] + (int)$team['medals']['silver'] + (int)$team['medals']['bronze'] ?></span></h6>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="details-card text-center">
                        <h5 class="mb-3"><i class="fas fa-star me-2"></i>Total Points</h5>
                        <h1 class="display-4 fw-bolder text-warning"><?= (int)$team['points'] ?></h1>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <footer class="bg-dark text-white py-3 mt-4">
        <div class="container text-center">
            <small>&copy; <?= date('Y') ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small>Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 