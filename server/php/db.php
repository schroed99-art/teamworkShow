<?php
/**
 * Shared DB + helper layer for the multi-tenant backend.
 * Loads config.php (VM-only, gitignored) or falls back to config.sample.php.
 */

function tw_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $real = __DIR__ . '/config.php';
        $cfg = is_file($real) ? require $real : require __DIR__ . '/config.sample.php';
    }
    return $cfg;
}

function tw_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = tw_config()['db'];
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Emit a JSON response and stop. */
function tw_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Read decoded JSON request body (for admin CRUD), or [] when absent/invalid. */
function tw_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
