<?php
// =============================================================
// index.php – Front controller / router
// CPSC 3750 Battleship Phase 1
// =============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Test-Mode');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/helpers.php';

// Parse method and path
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($path, '/');

// Strip a leading base path if running under MAMP subdirectory
// e.g. /battleship/players → /players
$base = '/battleship';
if (str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}

// ---------------------------------------------------------------
// Route dispatch
// ---------------------------------------------------------------

// Players
if ($path === '/players' && $method === 'POST') {
    require __DIR__ . '/api/players.php'; createPlayer(); exit;
}
if (preg_match('#^/players/(\d+)$#', $path, $m) && $method === 'GET') {
    require __DIR__ . '/api/players.php'; getPlayer((int)$m[1]); exit;
}
if ($path === '/players' && $method === 'GET') {
    require __DIR__ . '/api/players.php'; listPlayers(); exit;
}

// Games
if ($path === '/games' && $method === 'POST') {
    require __DIR__ . '/api/games.php'; createGame(); exit;
}
if ($path === '/games' && $method === 'GET') {
    require __DIR__ . '/api/games.php'; listGames(); exit;
}
if (preg_match('#^/games/(\d+)$#', $path, $m) && $method === 'GET') {
    require __DIR__ . '/api/games.php'; getGame((int)$m[1]); exit;
}

// Join game
if (preg_match('#^/games/(\d+)/join$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/api/games.php'; joinGame((int)$m[1]); exit;
}

// Place ships
if (preg_match('#^/games/(\d+)/ships$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/api/ships.php'; placeShips((int)$m[1]); exit;
}

// Start game
if (preg_match('#^/games/(\d+)/start$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/api/games.php'; startGame((int)$m[1]); exit;
}

// Fire / make move
if (preg_match('#^/games/(\d+)/move$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/api/moves.php'; fireShot((int)$m[1]); exit;
}

// Move history
if (preg_match('#^/games/(\d+)/moves$#', $path, $m) && $method === 'GET') {
    require __DIR__ . '/api/moves.php'; getMoves((int)$m[1]); exit;
}

// ---------------------------------------------------------------
// Test-mode endpoints (autograder only)
// ---------------------------------------------------------------
if (preg_match('#^/test/games/(\d+)/ships$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/test/test_endpoints.php'; testPlaceShips((int)$m[1]); exit;
}
if (preg_match('#^/test/games/(\d+)/board$#', $path, $m) && $method === 'GET') {
    require __DIR__ . '/test/test_endpoints.php'; testRevealBoard((int)$m[1]); exit;
}
if (preg_match('#^/test/games/(\d+)/reset$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/test/test_endpoints.php'; testResetGame((int)$m[1]); exit;
}
if (preg_match('#^/test/games/(\d+)/set-turn$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/test/test_endpoints.php'; testSetTurn((int)$m[1]); exit;
}

// ---------------------------------------------------------------
// 404 fallback
// ---------------------------------------------------------------
json_response(['error' => 'Route not found.'], 404);
