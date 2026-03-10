<?php
define('DB_HOST',    'localhost');
define('DB_PORT',    8889);
define('DB_NAME',    'battleship');
define('DB_USER',    'root');
define('DB_PASS',    'root');
define('DB_CHARSET', 'utf8mb4');
define('TEST_PASSWORD', 'cpsc3750testmode');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // MAMP uses a Unix socket, not TCP
        $dsn = 'mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=battleship;charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'DB failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}
