-- =============================================================
-- CPSC 3750 – Battleship Phase 1 Database Schema (HOSTINGER)
-- =============================================================

-- IMPORTANT:
-- Database is created via Hostinger panel
-- Do NOT include CREATE DATABASE or USE statements

-- -------------------------------------------------------------
-- Players
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Players (
    player_id   INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    wins        INT          NOT NULL DEFAULT 0,
    losses      INT          NOT NULL DEFAULT 0,
    total_shots INT          NOT NULL DEFAULT 0,
    total_hits  INT          NOT NULL DEFAULT 0
);

-- -------------------------------------------------------------
-- Games
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Games (
    game_id      INT AUTO_INCREMENT PRIMARY KEY,
    grid_size    INT          NOT NULL DEFAULT 10,
    status       ENUM('waiting','active','finished') NOT NULL DEFAULT 'waiting',
    current_turn INT          NULL,
    winner_id    INT          NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- -------------------------------------------------------------
-- GamePlayers
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS GamePlayers (
    gp_id         INT AUTO_INCREMENT PRIMARY KEY,
    game_id       INT          NOT NULL,
    player_id     INT          NOT NULL,
    turn_order    INT          NOT NULL DEFAULT 0,
    ships_json    JSON         NULL,
    board_json    JSON         NULL,
    is_eliminated TINYINT(1)   NOT NULL DEFAULT 0,
    joined_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (game_id, player_id)
);

-- -------------------------------------------------------------
-- Moves
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Moves (
    move_id      INT AUTO_INCREMENT PRIMARY KEY,
    game_id      INT          NOT NULL,
    attacker_id  INT          NOT NULL,
    target_id    INT          NOT NULL,
    coord_row    INT          NOT NULL,
    coord_col    INT          NOT NULL,
    result       ENUM('hit','miss','sunk') NOT NULL,
    ship_type    VARCHAR(30)  NULL,
    fired_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- -------------------------------------------------------------
-- Foreign Keys (added AFTER tables exist)
-- -------------------------------------------------------------

ALTER TABLE Games
ADD CONSTRAINT fk_games_current_turn 
FOREIGN KEY (current_turn) REFERENCES Players(player_id)
ON DELETE SET NULL;

ALTER TABLE Games
ADD CONSTRAINT fk_games_winner       
FOREIGN KEY (winner_id) REFERENCES Players(player_id)
ON DELETE SET NULL;

ALTER TABLE GamePlayers
ADD CONSTRAINT fk_gp_game   
FOREIGN KEY (game_id) REFERENCES Games(game_id)
ON DELETE CASCADE;

ALTER TABLE GamePlayers
ADD CONSTRAINT fk_gp_player 
FOREIGN KEY (player_id) REFERENCES Players(player_id);

ALTER TABLE Moves
ADD CONSTRAINT fk_moves_game     
FOREIGN KEY (game_id) REFERENCES Games(game_id)
ON DELETE CASCADE;

ALTER TABLE Moves
ADD CONSTRAINT fk_moves_attacker 
FOREIGN KEY (attacker_id) REFERENCES Players(player_id);

ALTER TABLE Moves
ADD CONSTRAINT fk_moves_target   
FOREIGN KEY (target_id) REFERENCES Players(player_id);

-- -------------------------------------------------------------
-- Indexes
-- -------------------------------------------------------------
CREATE INDEX idx_moves_game       ON Moves(game_id);
CREATE INDEX idx_moves_attacker   ON Moves(attacker_id);
CREATE INDEX idx_gameplayers_game ON GamePlayers(game_id);