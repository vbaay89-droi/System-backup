CREATE TABLE IF NOT EXISTS teams (
    team_id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS matches (
    match_id INT AUTO_INCREMENT PRIMARY KEY,
    team_1_id INT NOT NULL,
    team_2_id INT NOT NULL,
    winner_id INT,
    loser_id INT,
    round VARCHAR(50) NOT NULL,
    match_type ENUM('Regular', 'Semifinal', 'Bronze', 'Gold Final') NOT NULL,
    match_date DATE NOT NULL,
    FOREIGN KEY (team_1_id) REFERENCES teams(team_id),
    FOREIGN KEY (team_2_id) REFERENCES teams(team_id),
    FOREIGN KEY (winner_id) REFERENCES teams(team_id),
    FOREIGN KEY (loser_id) REFERENCES teams(team_id)
);

CREATE TABLE IF NOT EXISTS medals (
    team_id INT PRIMARY KEY,
    gold INT DEFAULT 0,
    silver INT DEFAULT 0,
    bronze INT DEFAULT 0,
    total INT AS (gold + silver + bronze) STORED,
    FOREIGN KEY (team_id) REFERENCES teams(team_id)
); 