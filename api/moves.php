<?php
// =============================================================
// api/moves.php – Fire shot + move history
// =============================================================
// POST /games/{id}/move
// Body: { "attacker_id": 1, "target_id": 2, "row": 3, "col": 4 }
//
// GET /games/{id}/moves
// =============================================================

declare(strict_types=1);

function fireShot(int $game_id): void {
    $db   = get_db();
    $body = get_body();

    $attacker_id = isset($body['attacker_id']) ? (int)$body['attacker_id'] : 0;
    $target_id   = isset($body['target_id'])   ? (int)$body['target_id']   : 0;
    $row         = isset($body['row'])          ? (int)$body['row']         : -1;
    $col         = isset($body['col'])          ? (int)$body['col']         : -1;

    if ($attacker_id <= 0 || $target_id <= 0) {
        json_response(['error' => 'attacker_id and target_id are required.'], 400);
    }
    if ($attacker_id === $target_id) {
        json_response(['error' => 'Cannot fire at yourself.'], 400);
    }
    if ($row < 0 || $col < 0) {
        json_response(['error' => 'row and col are required and must be non-negative.'], 400);
    }

    // Fetch game (with lock for concurrency safety)
    $db->beginTransaction();
    try {
        $g = $db->prepare(
            'SELECT game_id, grid_size, status, current_turn FROM Games WHERE game_id = ? FOR UPDATE'
        );
        $g->execute([$game_id]);
        $game = $g->fetch();

        if (!$game) {
            $db->rollBack();
            json_response(['error' => 'Game not found.'], 404);
        }
        if ($game['status'] !== 'active') {
            $db->rollBack();
            json_response(['error' => 'Game is not active.'], 409);
        }
        if ((int)$game['current_turn'] !== $attacker_id) {
            $db->rollBack();
            json_response(['error' => 'It is not your turn.'], 403);
        }

        $grid_size = (int)$game['grid_size'];
        if ($row >= $grid_size || $col >= $grid_size) {
            $db->rollBack();
            json_response(['error' => "Coordinate out of bounds (grid is {$grid_size}x{$grid_size})."], 400);
        }

        // Verify attacker is in game and not eliminated
        $atk = $db->prepare(
            'SELECT gp_id, is_eliminated FROM GamePlayers
             WHERE game_id = ? AND player_id = ?'
        );
        $atk->execute([$game_id, $attacker_id]);
        $atk_row = $atk->fetch();
        if (!$atk_row || $atk_row['is_eliminated']) {
            $db->rollBack();
            json_response(['error' => 'Attacker is not an active player in this game.'], 403);
        }

        // Fetch target's ships and board
        $tgt = $db->prepare(
            'SELECT gp_id, ships_json, board_json, is_eliminated
             FROM GamePlayers WHERE game_id = ? AND player_id = ?'
        );
        $tgt->execute([$game_id, $target_id]);
        $tgt_row = $tgt->fetch();

        if (!$tgt_row || $tgt_row['is_eliminated']) {
            $db->rollBack();
            json_response(['error' => 'Target is not a valid active player in this game.'], 400);
        }

        $ships = json_decode($tgt_row['ships_json'] ?? '[]', true);
        $board = json_decode($tgt_row['board_json']  ?? '[]', true);

        // Check for duplicate shot
        if (($board[$row][$col] ?? 0) !== 0) {
            $db->rollBack();
            json_response(['error' => 'You already fired at this coordinate.'], 409);
        }

        // Determine hit/miss/sunk
        $result    = 'miss';
        $ship_type = null;
        $ship_sunk = false;

        foreach ($ships as &$ship) {
            foreach ($ship['coordinates'] as $coord) {
                if ((int)$coord[0] === $row && (int)$coord[1] === $col) {
                    $result    = 'hit';
                    $ship_type = $ship['type'];
                    $ship['hits'][] = [$row, $col];

                    // Check if this ship is now sunk
                    $defs = ship_definitions();
                    $size = $defs[strtolower($ship['type'])] ?? 0;
                    if (count($ship['hits']) >= $size) {
                        $result    = 'sunk';
                        $ship_sunk = true;
                    }
                    break 2;
                }
            }
        }
        unset($ship);

        // Mark board cell: 1 = miss, 2 = hit/sunk
        $board[$row][$col] = ($result === 'miss') ? 1 : 2;

        // Log the move
        $move = $db->prepare(
            'INSERT INTO Moves (game_id, attacker_id, target_id, coord_row, coord_col, result, ship_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $move->execute([$game_id, $attacker_id, $target_id, $row, $col, $result, $ship_type]);
        $move_id = (int)$db->lastInsertId();

        // Update target's ships and board
        $updateTgt = $db->prepare(
            'UPDATE GamePlayers SET ships_json = ?, board_json = ?
             WHERE game_id = ? AND player_id = ?'
        );
        $updateTgt->execute([
            json_encode($ships),
            json_encode($board),
            $game_id,
            $target_id,
        ]);

        // Update attacker stats
        $db->prepare(
            'UPDATE Players SET total_shots = total_shots + 1,
             total_hits = total_hits + ?
             WHERE player_id = ?'
        )->execute([$result !== 'miss' ? 1 : 0, $attacker_id]);

        // Check if target is eliminated (all ships sunk)
        $eliminated      = false;
        $game_over       = false;
        $winner_id       = null;
        $next_player     = null;

        if (all_ships_sunk($ships)) {
            $eliminated = true;
            $db->prepare(
                'UPDATE GamePlayers SET is_eliminated = 1 WHERE game_id = ? AND player_id = ?'
            )->execute([$game_id, $target_id]);

            // Check if only one player remains
            $remaining = $db->prepare(
                'SELECT player_id FROM GamePlayers
                 WHERE game_id = ? AND is_eliminated = 0'
            );
            $remaining->execute([$game_id]);
            $alive = $remaining->fetchAll();

            if (count($alive) === 1) {
                $winner_id = (int)$alive[0]['player_id'];
                $game_over = true;

                $db->prepare(
                    'UPDATE Games SET status = "finished", winner_id = ?, current_turn = NULL
                     WHERE game_id = ?'
                )->execute([$winner_id, $game_id]);

                // Update winner/loser stats
                $db->prepare(
                    'UPDATE Players SET wins = wins + 1 WHERE player_id = ?'
                )->execute([$winner_id]);

                // Mark all non-winners as losses
                $losers = $db->prepare(
                    'SELECT player_id FROM GamePlayers WHERE game_id = ? AND player_id != ?'
                );
                $losers->execute([$game_id, $winner_id]);
                foreach ($losers->fetchAll() as $loser) {
                    $db->prepare(
                        'UPDATE Players SET losses = losses + 1 WHERE player_id = ?'
                    )->execute([$loser['player_id']]);
                }
            }
        }

        // Advance turn if game still active
        if (!$game_over) {
            $next_player = advance_turn($db, $game_id, $attacker_id);
            $db->prepare(
                'UPDATE Games SET current_turn = ? WHERE game_id = ?'
            )->execute([$next_player, $game_id]);
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        json_response(['error' => 'Move failed: ' . $e->getMessage()], 500);
    }

    $response = [
        'move_id'     => $move_id,
        'game_id'     => $game_id,
        'attacker_id' => $attacker_id,
        'target_id'   => $target_id,
        'row'         => $row,
        'col'         => $col,
        'result'      => $result,
        'ship_type'   => $ship_type,
        'fired_at'    => date('c'),
    ];

    if ($eliminated) {
        $response['eliminated'] = $target_id;
    }
    if ($game_over) {
        $response['game_over'] = true;
        $response['winner_id'] = $winner_id;
    } else {
        $response['next_turn'] = $next_player;
    }

    json_response($response);
}

/**
 * Advance current_turn to the next non-eliminated player.
 * Returns the next player's ID.
 */
function advance_turn(PDO $db, int $game_id, int $current_attacker): int {
    $players = $db->prepare(
        'SELECT player_id, turn_order FROM GamePlayers
         WHERE game_id = ? AND is_eliminated = 0
         ORDER BY turn_order ASC'
    );
    $players->execute([$game_id]);
    $alive = $players->fetchAll();

    // Find index of current attacker
    $idx   = 0;
    $count = count($alive);
    for ($i = 0; $i < $count; $i++) {
        if ((int)$alive[$i]['player_id'] === $current_attacker) {
            $idx = $i;
            break;
        }
    }
    $next_idx = ($idx + 1) % $count;
    return (int)$alive[$next_idx]['player_id'];
}

function getMoves(int $game_id): void {
    $db = get_db();

    // Verify game exists
    $g = $db->prepare('SELECT game_id FROM Games WHERE game_id = ?');
    $g->execute([$game_id]);
    if (!$g->fetch()) {
        json_response(['error' => 'Game not found.'], 404);
    }

    $stmt = $db->prepare(
        'SELECT m.move_id, m.attacker_id, pa.username AS attacker_username,
                m.target_id, pt.username AS target_username,
                m.coord_row, m.coord_col, m.result, m.ship_type, m.fired_at
         FROM Moves m
         JOIN Players pa ON pa.player_id = m.attacker_id
         JOIN Players pt ON pt.player_id = m.target_id
         WHERE m.game_id = ?
         ORDER BY m.move_id ASC'
    );
    $stmt->execute([$game_id]);
    json_response($stmt->fetchAll());
}
