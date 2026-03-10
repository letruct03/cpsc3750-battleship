<?php
// =============================================================
// config/helpers.php – Shared utility functions
// =============================================================

/**
 * Send a JSON response and exit.
 */
function json_response(mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Parse and return the JSON request body.
 * Returns empty array on failure.
 */
function get_body(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Validate test-mode header. Returns true if valid.
 */
function require_test_mode(): void {
    $header = $_SERVER['HTTP_X_TEST_MODE'] ?? '';
    if ($header !== TEST_PASSWORD) {
        json_response(['error' => 'Forbidden: invalid test mode token.'], 403);
    }
}

/**
 * Standard ship definitions: name => size
 */
function ship_definitions(): array {
    return [
        'carrier'    => 5,
        'battleship' => 4,
        'cruiser'    => 3,
        'submarine'  => 3,
        'destroyer'  => 2,
    ];
}

/**
 * Expand a ship placement into an array of [row, col] coordinates.
 * $ship = ['type'=>..., 'row'=>..., 'col'=>..., 'orientation'=>'H'|'V']
 */
function expand_ship(array $ship, int $grid_size): array|false {
    $defs   = ship_definitions();
    $type   = strtolower($ship['type'] ?? '');
    $size   = $defs[$type] ?? null;
    if ($size === null) return false;

    $row    = (int)($ship['row'] ?? -1);
    $col    = (int)($ship['col'] ?? -1);
    $orient = strtoupper($ship['orientation'] ?? 'H');

    $coords = [];
    for ($i = 0; $i < $size; $i++) {
        $r = $orient === 'V' ? $row + $i : $row;
        $c = $orient === 'H' ? $col + $i : $col;
        if ($r < 0 || $r >= $grid_size || $c < 0 || $c >= $grid_size) return false;
        $coords[] = [$r, $c];
    }
    return $coords;
}

/**
 * Check if all ships for a player are sunk.
 * $ships_json: array of ship objects with 'hits' arrays
 */
function all_ships_sunk(array $ships): bool {
    $defs = ship_definitions();
    foreach ($ships as $ship) {
        $size = $defs[strtolower($ship['type'])] ?? 0;
        $hits = count($ship['hits'] ?? []);
        if ($hits < $size) return false;
    }
    return true;
}
