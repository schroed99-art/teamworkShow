<?php
/**
 * Configuration template. Copy to config.php on the server and fill in real values.
 * config.php is gitignored and lives ONLY on the VM (never commit secrets).
 * If config.php is missing, db.php falls back to these sample defaults.
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'teamworkshow',
        'user' => 'teamworkshow',
        'pass' => 'CHANGE_ME',
    ],
    // Empty => weather.php returns a clear stub ({"stub":true}) and the gate stays green.
    'openweather_api_key' => '',
    // Simple dashboard-admin login (step 6).
    'admin_password' => 'CHANGE_ME',
];
