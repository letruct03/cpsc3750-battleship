<?php
// =============================================================
// api/games.php – Game lifecycle endpoints
// =============================================================
// POST /games                  → create game
// GET  /games                  → list games
// GET  /games/{id}             → get game state
// POST /games/{id}/join        → join game
// POST /games/{id}/start       → start game (lock ships, begin)
// =============================================================

declare(strict_types=1);
function createGame(): void {
    $db   = get_db();
    $body = get_body();

    $grid_size  = (int)($body['grid_size'] ?? 10);
    $creator_id = (int)($body['player_id'] ?? 0);

    if ($grid_size < 5 || $grid_size > 20) {
        json_response(['error' => 'Invalid grid size.'], 400);
    }
    if ($creator_id <= 0) {
        json_response(['error' => 'player_id required'], 400);
    }

    $check = $db->prepare('SELECT player_id FROM Players WHERE player_id = ?');
    $check->execute([$creator_id]);
    if (!$check->fetch()) {
        json_response(['error' => 'Player not found'], 404);
    }

    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            'INSERT INTO Games (grid_size, status, current_turn)
             VALUES (?, "waiting", NULL)'
        );
        $stmt->execute([$grid_size]);
        $game_id = (int)$db->lastInsertId();

        $db->prepare(
            'INSERT INTO GamePlayers (game_id, player_id, turn_order)
             VALUES (?, ?, 0)'
        )->execute([$game_id, $creator_id]);

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        json_response(['error' => 'Failed'], 500);
    }

    json_response([
        'game_id' => $game_id,
        'status' => 'waiting'
    ], 201);
}
function listGames(): void {
    $db     = get_db();
    $status = $_GET['status'] ?? null;

    $sql    = 'SELECT game_id, grid_size, status, current_turn, winner_id, created_at, updated_at
               FROM Games';
    $params = [];

    if ($status !== null) {
        $sql    .= ' WHERE status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 50';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    json_response($stmt->fetchAll());
}

function getGame(int $id): void {
    $db   = get_db();
    $stmt = $db->prepare(
        'SELECT game_id, grid_size, status, current_turn, winner_id, created_at, updated_at
         FROM Games WHERE game_id = ?'
    );
    $stmt->execute([$id]);
    $game = $stmt->fetch();

    if (!$game) {
        json_response(['error' => 'Game not found.'], 404);
    }

    // Attach players (no ships exposed here)
    $players = $db->prepare(
        'SELECT gp.player_id, p.username, gp.turn_order, gp.is_eliminated
         FROM GamePlayers gp
         JOIN Players p ON p.player_id = gp.player_id
         WHERE gp.game_id = ?
         ORDER BY gp.turn_order ASC'
    );
    $players->execute([$id]);
    $game['players'] = $players->fetchAll();

    json_response($game);
}

function joinGame(int $game_id): void {
    $db   = get_db();
    $body = get_body();

    $player_id = isset($body['player_id']) ? (int)$body['player_id'] : 0;
    if ($player_id <= 0) {
        json_response(['error' => 'player_id is required.'], 400);
    }

    // Verify game exists and is waiting
    $g = $db->prepare('SELECT game_id, status FROM Games WHERE game_id = ?');
    $g->execute([$game_id]);
    $game = $g->fetch();
    if (!$game) {
        json_response(['error' => 'Game not found.'], 404);
    }
    if ($game['status'] !== 'waiting') {
        json_response(['error' => 'Game is not accepting new players.'], 409);
    }

    // Verify player exists
    $p = $db->prepare('SELECT player_id FROM Players WHERE player_id = ?');
    $p->execute([$player_id]);
    if (!$p->fetch()) {
        json_response(['error' => 'Player not found.'], 404);
    }

    // Check not already in game
    $already = $db->prepare(
        'SELECT gp_id FROM GamePlayers WHERE game_id = ? AND player_id = ?'
    );
    $already->execute([$game_id, $player_id]);
    if ($already->fetch()) {
        json_response(['error' => 'Player already in this game.'], 409);
    }

    // Get next turn_order
    $order = $db->prepare(
        'SELECT COALESCE(MAX(turn_order), -1) + 1 AS next_order FROM GamePlayers WHERE game_id = ?'
    );
    $order->execute([$game_id]);
    $next = (int)$order->fetch()['next_order'];

    $stmt = $db->prepare(
        'INSERT INTO GamePlayers (game_id, player_id, turn_order) VALUES (?, ?, ?)'
    );
    $stmt->execute([$game_id, $player_id, $next]);

    json_response([
        'game_id'    => $game_id,
        'player_id'  => $player_id,
        'turn_order' => $next,
        'message'    => 'Joined game successfully.',
    ], 200);
}

function startGame(int $game_id): void {
    $db   = get_db();
    $body = get_body();

    $requesting_player = isset($body['player_id']) ? (int)$body['player_id'] : 0;

    // Fetch game
    $g = $db->prepare('SELECT * FROM Games WHERE game_id = ?');
    $g->execute([$game_id]);
    $game = $g->fetch();
    if (!$game) {
        json_response(['error' => 'Game not found.'], 404);
    }
    if ($game['status'] !== 'waiting') {
        json_response(['error' => 'Game has already started or finished.'], 409);
    }

    // All players must have placed ships
    $players = $db->prepare(
        'SELECT player_id, ships_json FROM GamePlayers WHERE game_id = ?'
    );
    $players->execute([$game_id]);
    $rows = $players->fetchAll();

    if (count($rows) < 2) {
        json_response(['error' => 'At least 2 players required to start.'], 400);
    }

    foreach ($rows as $row) {
        if (empty($row['ships_json'])) {
            json_response([
                'error'     => 'All players must place ships before starting.',
                'player_id' => (int)$row['player_id'],
            ], 400);
        }
    }

    // First turn goes to turn_order = 0
    $first = $db->prepare(
        'SELECT player_id FROM GamePlayers WHERE game_id = ? ORDER BY turn_order ASC LIMIT 1'
    );
    $first->execute([$game_id]);
    $first_player = (int)$first->fetch()['player_id'];

    $update = $db->prepare(
        'UPDATE Games SET status = "active", current_turn = ? WHERE game_id = ?'
    );
    $update->execute([$first_player, $game_id]);

    json_response([
        'game_id'      => $game_id,
        'status'       => 'active',
        'current_turn' => $first_player,
        'message'      => 'Game started.',
    ]);
}
