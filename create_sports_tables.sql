-- TEAMS table (shared by all sports)
CREATE TABLE IF NOT EXISTS teams (
    team_id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(100) NOT NULL UNIQUE
);

-- MATCHES table (shared, but filtered by sport)
CREATE TABLE IF NOT EXISTS matches (
    match_id INT AUTO_INCREMENT PRIMARY KEY,
    team_1_id INT NOT NULL,
    team_2_id INT NOT NULL,
    winner_id INT,
    loser_id INT,
    round VARCHAR(50) NOT NULL,
    match_type ENUM('Regular', 'Semifinal', 'Bronze', 'Gold Final') NOT NULL,
    match_date DATE NOT NULL,
    score1 INT NOT NULL DEFAULT 0,
    score2 INT NOT NULL DEFAULT 0,
    sport VARCHAR(50) NOT NULL DEFAULT 'basketball',
    FOREIGN KEY (team_1_id) REFERENCES teams(team_id),
    FOREIGN KEY (team_2_id) REFERENCES teams(team_id),
    FOREIGN KEY (winner_id) REFERENCES teams(team_id),
    FOREIGN KEY (loser_id) REFERENCES teams(team_id)
);

-- MEDALS table (shared, but filtered by sport)
CREATE TABLE IF NOT EXISTS medals (
    team_id INT NOT NULL,
    gold INT DEFAULT 0,
    silver INT DEFAULT 0,
    bronze INT DEFAULT 0,
    sport VARCHAR(50) NOT NULL DEFAULT 'basketball',
    PRIMARY KEY (team_id, sport),
    FOREIGN KEY (team_id) REFERENCES teams(team_id)
); 