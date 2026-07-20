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
    // Secret for the URL-triggered device monitor (KAS cron). Empty => web call denied.
    'cron_key' => '',
    // Outgoing mail (SMTP). Empty host/user/pass => mailer disabled (degrades to a
    // no-op + log). On the VM, fill these in config.php; on All-Inkl use app.env.
    'smtp' => [
        'host'      => '',
        'port'      => '465',
        'security'  => 'ssl', // ssl (465) | tls (587, STARTTLS) | none
        'user'      => '',
        'pass'      => '',
        'from'      => '',    // empty => same as user
        'from_name' => 'TeamworkShow',
        'alarm_to'  => '',    // comma list; empty => all active admins
    ],
];
