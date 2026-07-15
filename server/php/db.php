<?php
/**
 * Shared DB + helper layer for the multi-tenant backend.
 * Loads config.php (VM-only, gitignored) or falls back to config.sample.php.
 */

function tw_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    // 1) Legacy: config.php (VM-only, gitignored) still wins for backwards compat.
    $real = __DIR__ . '/config.php';
    if (is_file($real)) {
        return $cfg = require $real;
    }
    // 2) env file (All-Inkl & co.), kept OUTSIDE the web root. TW_CONFIG works for
    //    both web (.user.ini) and CLI (cron/import); then a private dir above the
    //    docroot; then a repo-level config/ (only meaningful for non-docroot layouts).
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $candidates = array_filter([
        getenv('TW_CONFIG') ?: null,
        $docroot ? dirname($docroot) . '/teamworkshow-private/app.env' : null,
        dirname(__DIR__) . '/config/app.env',
    ]);
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $cfg = tw_load_env($path);
        }
    }
    // 3) Fallback: sample defaults (keeps a fresh checkout / php -l runnable).
    return $cfg = require __DIR__ . '/config.sample.php';
}

/**
 * Parse a KEY=VALUE .env file into the same array shape tw_config() returns.
 * Blank lines and lines starting with # are ignored; surrounding quotes stripped.
 */
function tw_load_env(string $path): array
{
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
    return [
        'db' => [
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'name' => $env['DB_NAME'] ?? '',
            'user' => $env['DB_USER'] ?? '',
            'pass' => $env['DB_PASS'] ?? '',
        ],
        'openweather_api_key' => $env['OPENWEATHER_API_KEY'] ?? '',
        'admin_password'      => $env['ADMIN_PASSWORD'] ?? '',
        'cron_key'            => $env['CRON_KEY'] ?? '',
    ];
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
