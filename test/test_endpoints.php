<?php
// =============================================================
// test/test_endpoints.php – Autograder test-mode endpoints
// =============================================================
// ALL endpoints here require: X-Test-Mode: <TEST_PASSWORD>
// Returns 403 if header missing or incorrect.
//
// POST /test/games/{id}/ships      → deterministic ship placement
// GET  /test/games/{id}/board      → reveal full board state
// POST /test/games/{id}/reset      → reset game (preserve stats)
// POST /test/games/{id}/set-turn   → force turn to a player
// =============================================================

declare(strict_types=1);

// ---------------------------------------------------------------
// POST /test/games/{id}/ships
// Body: { "playerId": 1, "ships": [ { "type": "destroyer",
//         "coordinates": [[0,0],[0,1]] } ] }
// ---------------------------------------------------------------
function testPlaceShips(int $game_id): void {
    require_test_mode();

    $db   = get_db();
    $body = get_body();

    $player_id = isset($body['playerId']) ? (int)$body['playerId'] : 0;
    $ships_in  = $body['ships'] ?? null;

    if ($player_id <= 0) {
        json_response(['error' => 'playerId is required.'], 400);
    }
    if (!is_array($ships_in) || empty($ships_in)) {
        json_response(['error' => 'ships array is required.'], 400);
    }

    // Fetch game
    $g = $db->prepare('SELECT grid_size, status FROM Games WHERE game_id = ?');
    $g->execute([$game_id]);
    $game = $g->fetch();
    if (!$game) {
        json_response(['error' => 'Game not found.'], 404);
    }
    if ($game['status'] !== 'waiting') {
        json_response(['error' => 'Ships can only be placed while game is in waiting status.'], 409);
    }

    $grid_size = (int)$game['grid_size'];
    $defs      = ship_definitions();

    // Validate each ship using explicit coordinates
    $occupied  = [];
    $ships_out = [];

    foreach ($ships_in as $ship) {
        $type   = strtolower($ship['type'] ?? '');
        $coords = $ship['coordinates'] ?? [];

        if (!isset($defs[$type])) {
            json_response(['error' => "Unknown ship type: $type"], 400);
        }

        $expected_size = $defs[$type];
        if (count($coords) !== $expected_size) {
            json_response([
                'error' => "Ship '$type' must have exactly $expected_size coordinates.",
            ], 400);
        }

        foreach ($coords as $coord) {
            $r = (int)($coord[0] ?? -1);
            $c = (int)($coord[1] ?? -1);

            if ($r < 0 || $r >= $grid_size || $c < 0 || $c >= $grid_size) {
                json_response([
                    'error'      => 'Coordinate out of bounds.',
                    'coordinate' => [$r, $c],
                ], 400);
            }

            $key = "$r,$c";
            if (isset($occupied[$key])) {
                json_response([
                    'error'      => 'Ships overlap.',
                    'coordinate' => [$r, $c],
                ], 400);
            }
            $occupied[$key] = true;
        }

        $ships_out[] = [
            'type'        => $type,
            'coordinates' => $coords,
            'hits'        => [],
        ];
    }

    $board = array_fill(0, $grid_size, array_fill(0, $grid_size, 0));

    $update = $db->prepare(
        'UPDATE GamePlayers SET ships_json = ?, board_json = ?
         WHERE game_id = ? AND player_id = ?'
    );
    $update->execute([
        json_encode($ships_out),
        json_encode($board),
        $game_id,
        $player_id,
    ]);

    if ($update->rowCount() === 0) {
        json_response(['error' => 'Player not found in this game.'], 404);
    }

    json_response([
        'game_id'   => $game_id,
        'player_id' => $player_id,
        'ships'     => $ships_out,
        'message'   => 'Test ships placed.',
    ]);
}

// ---------------------------------------------------------------
// GET /test/games/{id}/board?playerId=...
// Returns full board state: ship positions, hits, misses, sunk
// ---------------------------------------------------------------
function testRevealBoard(int $game_id): void {
    require_test_mode();

    $db        = get_db();
    $player_id = isset($_GET['playerId']) ? (int)$_GET['playerId'] : 0;

    if ($player_id <= 0) {
        json_response(['error' => 'playerId query param is required.'], 400);
    }

    $g = $db->prepare('SELECT grid_size FROM Games WHERE game_id = ?');
    $g->execute([$game_id]);
    $game = $g->fetch();
    if (!$game) {
        json_response(['error' => 'Game not found.'], 404);
    }

    $tgt = $db->prepare(
        'SELECT ships_json, board_json FROM GamePlayers
         WHERE game_id = ? AND player_id = ?'
    );
    $tgt->execute([$game_id, $player_id]);
    $row = $tgt->fetch();

    if (!$row) {
        json_response(['error' => 'Player not found in this game.'], 404);
    }

    $ships = json_decode($row['ships_json'] ?? '[]', true);
    $board = json_decode($row['board_json']  ?? '[]', true);
    $defs  = ship_definitions();

    // Annotate sunk status
    foreach ($ships as &$ship) {
        $size         = $defs[strtolower($ship['type'])] ?? 0;
        $ship['sunk'] = count($ship['hits'] ?? []) >= $size;
    }
    unset($ship);

    json_response([
        'game_id'   => $game_id,
        'player_id' => $player_id,
        'grid_size' => (int)$game['grid_size'],
        'ships'     => $ships,
        'board'     => $board,   // 0=untouched, 1=miss, 2=hit
    ]);
}

// ---------------------------------------------------------------
// POST /test/games/{id}/reset
// Resets board and ships; preserves player stats and records.
// ---------------------------------------------------------------
function testResetGame(int $game_id): void {
    require_test_mode();

    $db = get_db();

    $g = $db->prepare('SELECT game_id, status FROM Games WHERE game_id = ?');
    $g->execute([$game_id]);
    $game = $g->fetch();
    if (!$game) {
        json_response(['error' => 'Game not found.'], 404);
    }

    $db->beginTransaction();
    try {
        // Reset game state to waiting
        $db->prepare(
            'UPDATE Games SET status = "waiting", current_turn = NULL, winner_id = NULL
             WHERE game_id = ?'
        )->execute([$game_id]);

        // Clear ships, boards, and elimination flags for all players in game
        $db->prepare(
            'UPDATE GamePlayers
             SET ships_json = NULL, board_json = NULL, is_eliminated = 0
             WHERE game_id = ?'
        )->execute([$game_id]);

        // Clear move log for this game
        $db->prepare('DELETE FROM Moves WHERE game_id = ?')->execute([$game_id]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_response(['error' => 'Reset failed.'], 500);
    }

    json_response([
        'game_id' => $game_id,
        'status'  => 'waiting',
        'message' => 'Game reset. Player statistics preserved.',
    ]);
}

// ---------------------------------------------------------------
// POST /test/games/{id}/set-turn
// Body: { "playerId": 1 }
// Forces turn to a specific player for deterministic testing.
// ---------------------------------------------------------------
function testSetTurn(int $game_id): void {
    require_test_mode();

    $db        = get_db();
    $body      = get_body();
    $player_id = isset($body['playerId']) ? (int)$body['playerId'] : 0;

    if ($player_id <= 0) {
        json_response(['error' => 'playerId is required.'], 400);
    }

    $g = $db->prepare('SELECT status FROM Games WHERE game_id = ?');
    $g->execute([$game_id]);
    $game = $g->fetch();
    if (!$game) {
        json_response(['error' => 'Game not found.'], 404);
    }
    if ($game['status'] !== 'active') {
        json_response(['error' => 'Game must be active to set turn.'], 409);
    }

    // Verify player is active in game
    $p = $db->prepare(
        'SELECT gp_id FROM GamePlayers
         WHERE game_id = ? AND player_id = ? AND is_eliminated = 0'
    );
    $p->execute([$game_id, $player_id]);
    if (!$p->fetch()) {
        json_response(['error' => 'Player is not an active participant in this game.'], 400);
    }

    $db->prepare(
        'UPDATE Games SET current_turn = ? WHERE game_id = ?'
    )->execute([$player_id, $game_id]);

    json_response([
        'game_id'      => $game_id,
        'current_turn' => $player_id,
        'message'      => 'Turn forced for testing.',
    ]);
}
