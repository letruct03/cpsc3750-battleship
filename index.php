<?php
// =============================================================
// index.php – Front controller / router (AUTOGRADER READY)
// =============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Test-Password');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/helpers.php';

// ---------------------------------------------------------------
// Parse method + path
// ---------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/');

// remove index.php if present
$path = str_replace('/index.php', '', $path);

// 🔥 REQUIRED: strip /api prefix
$path = str_replace('/api', '', $path);

// fallback root
if ($path === '') {
    $path = '/';
}

// ---------------------------------------------------------------
// ROUTES
// ---------------------------------------------------------------

// ===================== PLAYERS =====================

// POST /api/players
if ($path === '/players' && $method === 'POST') {
    require __DIR__ . '/api/players.php';
    createPlayer();
    exit;
}

// GET /api/players
if ($path === '/players' && $method === 'GET') {
    require __DIR__ . '/api/players.php';
    listPlayers();
    exit;
}

// GET /api/players/{id}/stats  (REQUIRED)
if (preg_match('#^/players/(\d+)/stats$#', $path, $m) && $method === 'GET') {
    require __DIR__ . '/api/players.php';
    getPlayerStats((int)$m[1]);
    exit;
}

// ===================== GAMES =====================

// POST /api/games
if ($path === '/games' && $method === 'POST') {
    require __DIR__ . '/api/games.php';
    createGame();
    exit;
}

// GET /api/games
if ($path === '/games' && $method === 'GET') {
    require __DIR__ . '/api/games.php';
    listGames();
    exit;
}

// GET /api/games/{id}
if (preg_match('#^/games/(\d+)$#', $path, $m) && $method === 'GET') {
    require __DIR__ . '/api/games.php';
    getGame((int)$m[1]);
    exit;
}

// POST /api/games/{id}/join
if (preg_match('#^/games/(\d+)/join$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/api/games.php';
    joinGame((int)$m[1]);
    exit;
}

// POST /api/games/{id}/place  (RENAMED from ships)
if (preg_match('#^/games/(\d+)/place$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/api/ships.php';
    placeShips((int)$m[1]);
    exit;
}

// POST /api/games/{id}/start
if (preg_match('#^/games/(\d+)/start$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/api/games.php';
    startGame((int)$m[1]);
    exit;
}

// POST /api/games/{id}/fire  (RENAMED from move)
if (preg_match('#^/games/(\d+)/fire$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/api/moves.php';
    fireShot((int)$m[1]);
    exit;
}

// ===================== TEST MODE =====================

// POST /api/reset  (REQUIRED)
if ($path === '/reset' && $method === 'POST') {
    require __DIR__ . '/test/test_endpoints.php';
    testResetSystem();
    exit;
}

// POST /api/test/games/{id}/ships
if (preg_match('#^/test/games/(\d+)/ships$#', $path, $m) && $method === 'POST') {
    require __DIR__ . '/test/test_endpoints.php';
    testPlaceShips((int)$m[1]);
    exit;
}

// GET /api/test/games/{id}/board/{player_id}
if (preg_match('#^/test/games/(\d+)/board/(\d+)$#', $path, $m) && $method === 'GET') {
    require __DIR__ . '/test/test_endpoints.php';
    testRevealBoard((int)$m[1], (int)$m[2]);
    exit;
}

// ---------------------------------------------------------------
// 404 fallback
// ---------------------------------------------------------------
json_response(['error' => 'Route not found.'], 404);