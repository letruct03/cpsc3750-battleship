-- =============================================================
-- CPSC 3750 – Battleship Phase 1 Database Schema
-- Stack: PHP 8.x + MySQL | MAMP local
-- =============================================================

CREATE DATABASE IF NOT EXISTS battleship CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE battleship;

-- -------------------------------------------------------------
-- Players
-- Tracks every registered player and their lifetime statistics.
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
-- One row per game session. Supports 1-N players.
-- grid_size defaults to 10 (standard Battleship).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Games (
    game_id      INT AUTO_INCREMENT PRIMARY KEY,
    grid_size    INT          NOT NULL DEFAULT 10,
    status       ENUM('waiting','active','finished') NOT NULL DEFAULT 'waiting',
    current_turn INT          NULL,   -- FK → Players.player_id
    winner_id    INT          NULL,   -- FK → Players.player_id
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_games_current_turn FOREIGN KEY (current_turn) REFERENCES Players(player_id),
    CONSTRAINT fk_games_winner       FOREIGN KEY (winner_id)    REFERENCES Players(player_id)
);

-- -------------------------------------------------------------
-- GamePlayers (join table)
-- Tracks which players are in which game and their ship state.
-- ships_json stores placed ships as a JSON array.
-- board_json stores the player's own board hit/miss state.
-- turn_order determines rotation sequence.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS GamePlayers (
    gp_id        INT AUTO_INCREMENT PRIMARY KEY,
    game_id      INT          NOT NULL,
    player_id    INT          NOT NULL,
    turn_order   INT          NOT NULL DEFAULT 0,
    ships_json   JSON         NULL,   -- placed ship definitions
    board_json   JSON         NULL,   -- hit/miss grid overlay
    is_eliminated TINYINT(1)  NOT NULL DEFAULT 0,
    joined_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gp_game   FOREIGN KEY (game_id)   REFERENCES Games(game_id)   ON DELETE CASCADE,
    CONSTRAINT fk_gp_player FOREIGN KEY (player_id) REFERENCES Players(player_id),
    CONSTRAINT uq_gp        UNIQUE (game_id, player_id)
);

-- -------------------------------------------------------------
-- Moves
-- Immutable log of every shot fired. Append-only.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Moves (
    move_id      INT AUTO_INCREMENT PRIMARY KEY,
    game_id      INT          NOT NULL,
    attacker_id  INT          NOT NULL,  -- player who fired
    target_id    INT          NOT NULL,  -- player whose board was targeted
    coord_row    INT          NOT NULL,
    coord_col    INT          NOT NULL,
    result       ENUM('hit','miss','sunk') NOT NULL,
    ship_type    VARCHAR(30)  NULL,       -- populated on hit/sunk
    fired_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_moves_game     FOREIGN KEY (game_id)     REFERENCES Games(game_id)   ON DELETE CASCADE,
    CONSTRAINT fk_moves_attacker FOREIGN KEY (attacker_id) REFERENCES Players(player_id),
    CONSTRAINT fk_moves_target   FOREIGN KEY (target_id)   REFERENCES Players(player_id)
);

-- -------------------------------------------------------------
-- Indexes for common query patterns
-- -------------------------------------------------------------
CREATE INDEX idx_moves_game       ON Moves(game_id);
CREATE INDEX idx_moves_attacker   ON Moves(attacker_id);
CREATE INDEX idx_gameplayers_game ON GamePlayers(game_id);

-- =============================================================
-- Default ship definitions (reference only – not a table)
-- carrier:5, battleship:4, cruiser:3, submarine:3, destroyer:2
-- =============================================================
