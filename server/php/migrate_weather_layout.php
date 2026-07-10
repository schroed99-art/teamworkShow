<?php
/**
 * Idempotent migration: create the global `weather_layout` template table and seed
 * row id = 1 with a default config that mirrors the previous hardcoded interstitial
 * (city top, forecast centred, clock on the right).
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_weather_layout.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$exists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weather_layout'"
)->fetchColumn();

if ($exists === 0) {
    $pdo->exec(
        "CREATE TABLE weather_layout (
            id     TINYINT UNSIGNED NOT NULL DEFAULT 1,
            config TEXT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "created weather_layout\n";
} else {
    echo "weather_layout already present\n";
}

$hasRow = (int) $pdo->query("SELECT COUNT(*) FROM weather_layout WHERE id = 1")->fetchColumn();
if ($hasRow === 0) {
    $default = [
        'background' => '',
        'scrim'      => 20,
        'city'       => ['show' => true, 'h' => 'center', 'v' => 'header', 'size' => 34, 'color' => '#FFFFFF'],
        'forecast'   => ['show' => true, 'h' => 'center', 'v' => '4',      'size' => 100],
        'clock'      => ['show' => true, 'h' => 'right',  'v' => '5',      'size' => 150],
        'texts'      => [],
    ];
    $pdo->prepare('INSERT INTO weather_layout (id, config) VALUES (1, ?)')
        ->execute([json_encode($default, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    echo "seeded default weather_layout row\n";
} else {
    echo "weather_layout row already present\n";
}
