<?php
declare(strict_types=1);

function fireShot(int $game_id): void {
    $db   = get_db();
    $body = get_body();

    $attacker_id = (int)($body['attacker_id'] ?? 0);
    $target_id   = (int)($body['target_id'] ?? 0);
    $row         = (int)($body['row'] ?? -1);
    $col         = (int)($body['col'] ?? -1);

    if ($attacker_id <= 0 || $target_id <= 0) {
        json_response(['error' => 'attacker_id and target_id are required.'], 400);
    }
    if ($attacker_id === $target_id) {
        json_response(['error' => 'Cannot fire at yourself.'], 400);
    }
    if ($row < 0 || $col < 0) {
        json_response(['error' => 'Invalid coordinates.'], 400);
    }

    $db->beginTransaction();

    try {
        $g = $db->prepare(
            'SELECT grid_size, status, current_turn FROM Games WHERE game_id = ? FOR UPDATE'
        );
        $g->execute([$game_id]);
        $game = $g->fetch();

        if (!$game) {
            $db->rollBack();
            json_response(['error' => 'Game not found.'], 404);
        }

        if ($game['current_turn'] === null) {
            $db->rollBack();
            json_response(['error' => 'Game finished.'], 409);
        }

        if ($game['status'] !== 'active') {
            $db->rollBack();
            json_response(['error' => 'Game not active.'], 409);
        }

        if ((int)$game['current_turn'] !== $attacker_id) {
            $db->rollBack();
            json_response(['error' => 'Not your turn.'], 403);
        }

        $grid_size = (int)$game['grid_size'];
        if ($row >= $grid_size || $col >= $grid_size) {
            $db->rollBack();
            json_response(['error' => 'Out of bounds.'], 400);
        }

        // attacker validation
        $atk = $db->prepare(
            'SELECT is_eliminated FROM GamePlayers WHERE game_id = ? AND player_id = ?'
        );
        $atk->execute([$game_id, $attacker_id]);
        $atk_row = $atk->fetch();

        if (!$atk_row || $atk_row['is_eliminated']) {
            $db->rollBack();
            json_response(['error' => 'Invalid attacker.'], 403);
        }

        // target validation
        $tgt = $db->prepare(
            'SELECT ships_json, board_json, is_eliminated FROM GamePlayers WHERE game_id = ? AND player_id = ?'
        );
        $tgt->execute([$game_id, $target_id]);
        $tgt_row = $tgt->fetch();

        if (!$tgt_row || $tgt_row['is_eliminated']) {
            $db->rollBack();
            json_response(['error' => 'Invalid target.'], 403);
        }

        if (empty($tgt_row['ships_json'])) {
            $db->rollBack();
            json_response(['error' => 'Ships not placed.'], 400);
        }

        // duplicate check (DB)
        $dup = $db->prepare(
            'SELECT move_id FROM Moves WHERE game_id = ? AND coord_row = ? AND coord_col = ?'
        );
        $dup->execute([$game_id, $row, $col]);
        if ($dup->fetch()) {
            $db->rollBack();
            json_response(['error' => 'Duplicate move.'], 409);
        }

        $ships = json_decode($tgt_row['ships_json'], true);
        $board = json_decode($tgt_row['board_json'] ?? '[]', true);

        $result = 'miss';
        $ship_type = null;

        foreach ($ships as &$ship) {
            foreach ($ship['coordinates'] as $coord) {
                if ($coord[0] == $row && $coord[1] == $col) {
                    $result = 'hit';
                    $ship_type = $ship['type'];
                    $ship['hits'][] = [$row, $col];

                    $defs = ship_definitions();
                    if (count($ship['hits']) >= $defs[$ship['type']]) {
                        $result = 'sunk';
                    }
                    break 2;
                }
            }
        }

        $board[$row][$col] = ($result === 'miss') ? 1 : 2;

        // log move
        $db->prepare(
            'INSERT INTO Moves (game_id, attacker_id, target_id, coord_row, coord_col, result, ship_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$game_id, $attacker_id, $target_id, $row, $col, $result, $ship_type]);

        // update board
        $db->prepare(
            'UPDATE GamePlayers SET ships_json = ?, board_json = ? WHERE game_id = ? AND player_id = ?'
        )->execute([json_encode($ships), json_encode($board), $game_id, $target_id]);

        // 🔥 elimination + game over logic
        $eliminated = false;
        $game_over = false;
        $winner_id = null;

        if (all_ships_sunk($ships)) {
            $eliminated = true;

            $db->prepare(
                'UPDATE GamePlayers SET is_eliminated = 1 WHERE game_id = ? AND player_id = ?'
            )->execute([$game_id, $target_id]);

            $stmt = $db->prepare(
                'SELECT player_id FROM GamePlayers WHERE game_id = ? AND is_eliminated = 0'
            );
            $stmt->execute([$game_id]);
            $alive = $stmt->fetchAll();

            if (count($alive) === 1) {
                $game_over = true;
                $winner_id = (int)$alive[0]['player_id'];

                $db->prepare(
                    'UPDATE Games SET status = "finished", winner_id = ?, current_turn = NULL WHERE game_id = ?'
                )->execute([$winner_id, $game_id]);
            }
        }

        // next turn
        if (!$game_over) {
            $next = advance_turn($db, $game_id, $attacker_id);

            $db->prepare(
                'UPDATE Games SET current_turn = ? WHERE game_id = ?'
            )->execute([$next, $game_id]);
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        json_response(['error' => 'Move failed'], 500);
    }

    $response = [
        'result' => $result,
        'next_turn' => $next ?? null
    ];

    if ($eliminated) $response['eliminated'] = $target_id;
    if ($game_over) {
        $response['game_over'] = true;
        $response['winner_id'] = $winner_id;
    }

    json_response($response);
}