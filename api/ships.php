<?php
// =============================================================
// api/ships.php – Ship placement endpoint
// =============================================================
// POST /games/{id}/ships
// Body: { "player_id": 1, "ships": [ { "type": "carrier",
//         "row": 0, "col": 0, "orientation": "H" }, ... ] }
// =============================================================

declare(strict_types=1);

function placeShips(int $game_id): void {
    $db   = get_db();
    $body = get_body();

    $player_id = isset($body['player_id']) ? (int)$body['player_id'] : 0;
    $ships_in  = $body['ships'] ?? null;

    if ($player_id <= 0) {
        json_response(['error' => 'player_id is required.'], 400);
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

    // Verify player is in this game
    $gp = $db->prepare(
        'SELECT gp_id FROM GamePlayers WHERE game_id = ? AND player_id = ?'
    );
    $gp->execute([$game_id, $player_id]);
    if (!$gp->fetch()) {
        json_response(['error' => 'Player is not in this game.'], 403);
    }

    $grid_size = (int)$game['grid_size'];
    $defs      = ship_definitions();

    // Validate: exactly one of each ship type required
    $provided_types = array_map(
        fn($s) => strtolower($s['type'] ?? ''), $ships_in
    );
    $required_types = array_keys($defs);
    sort($provided_types);
    sort($required_types);
    if ($provided_types !== $required_types) {
        json_response([
            'error'    => 'Must place exactly one of each ship type.',
            'required' => $required_types,
            'provided' => $provided_types,
        ], 400);
    }

    // Expand all ships and check bounds + overlaps
    $occupied = [];
    $ships_out = [];

    foreach ($ships_in as $ship) {
        $coords = expand_ship($ship, $grid_size);
        if ($coords === false) {
            json_response([
                'error' => 'Invalid ship placement.',
                'ship'  => $ship,
            ], 400);
        }

        foreach ($coords as [$r, $c]) {
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
            'type'        => strtolower($ship['type']),
            'row'         => (int)$ship['row'],
            'col'         => (int)$ship['col'],
            'orientation' => strtoupper($ship['orientation']),
            'coordinates' => $coords,
            'hits'        => [],   // populated as game progresses
        ];
    }

    // Initialize empty board grid (0 = untouched)
    $board = array_fill(0, $grid_size, array_fill(0, $grid_size, 0));
    $board_json = json_encode($board);

    $update = $db->prepare(
        'UPDATE GamePlayers SET ships_json = ?, board_json = ?
         WHERE game_id = ? AND player_id = ?'
    );
    $update->execute([json_encode($ships_out), $board_json, $game_id, $player_id]);

    json_response([
        'game_id'   => $game_id,
        'player_id' => $player_id,
        'ships'     => $ships_out,
        'message'   => 'Ships placed successfully.',
    ]);
}
