<?php
// =============================================================
// api/players.php – Player management endpoints
// =============================================================
// POST /players          → create player
// GET  /players          → list all players
// GET  /players/{id}     → get single player + stats
// =============================================================

declare(strict_types=1);

function createPlayer(): void {
    $db   = get_db();
    $body = get_body();

    $username = trim($body['username'] ?? '');
    if ($username === '') {
        json_response(['error' => 'username is required.'], 400);
    }
    if (strlen($username) > 50) {
        json_response(['error' => 'username must be 50 characters or fewer.'], 400);
    }
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
        json_response(['error' => 'username may only contain letters, numbers, underscores, and hyphens.'], 400);
    }

    // Check uniqueness
    $check = $db->prepare('SELECT player_id FROM Players WHERE username = ?');
    $check->execute([$username]);
    if ($check->fetch()) {
        json_response(['error' => 'username already taken.'], 409);
    }

    $stmt = $db->prepare(
        'INSERT INTO Players (username) VALUES (?)'
    );
    $stmt->execute([$username]);
    $id = (int)$db->lastInsertId();

    json_response([
        'player_id'  => $id,
        'username'   => $username,
        'created_at' => date('c'),
        'wins'       => 0,
        'losses'     => 0,
        'total_shots'=> 0,
        'total_hits' => 0,
    ], 201);
}

function listPlayers(): void {
    $db   = get_db();
    $stmt = $db->query(
        'SELECT player_id, username, created_at, wins, losses, total_shots, total_hits
         FROM Players
         ORDER BY wins DESC, player_id ASC'
    );
    json_response($stmt->fetchAll());
}

function getPlayer(int $id): void {
    $db   = get_db();
    $stmt = $db->prepare(
        'SELECT player_id, username, created_at, wins, losses, total_shots, total_hits
         FROM Players WHERE player_id = ?'
    );
    $stmt->execute([$id]);
    $player = $stmt->fetch();

    if (!$player) {
        json_response(['error' => 'Player not found.'], 404);
    }

    // Attach recent game history
    $hist = $db->prepare(
        'SELECT g.game_id, g.status, g.created_at,
                CASE WHEN g.winner_id = ? THEN "win"
                     WHEN g.status = "finished" THEN "loss"
                     ELSE g.status END AS outcome
         FROM Games g
         JOIN GamePlayers gp ON gp.game_id = g.game_id
         WHERE gp.player_id = ?
         ORDER BY g.created_at DESC
         LIMIT 10'
    );
    $hist->execute([$id, $id]);
    $player['recent_games'] = $hist->fetchAll();

    json_response($player);
}
