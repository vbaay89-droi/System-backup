DROP TABLE IF EXISTS basketball_matches;
DROP TABLE IF EXISTS medal_tally;

CREATE TABLE IF NOT EXISTS basketball_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team1 VARCHAR(100) NOT NULL,
    team2 VARCHAR(100) NOT NULL,
    score1 INT NOT NULL,
    score2 INT NOT NULL,
    match_date DATE NOT NULL,
    medal VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS medal_tally (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team VARCHAR(100) NOT NULL,
    gold INT DEFAULT 0,
    silver INT DEFAULT 0,
    bronze INT DEFAULT 0,
    UNIQUE KEY unique_team (team)
); 