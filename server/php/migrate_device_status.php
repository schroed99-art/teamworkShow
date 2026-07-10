<?php
/**
 * Idempotent migration: create the `device_status` table used by
 * device_monitor.php to remember each device's last known online/offline state
 * (so it can log transitions and raise an alarm only once).
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_device_status.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS device_status (
        device_id INT UNSIGNED NOT NULL PRIMARY KEY,
        status    VARCHAR(16) NOT NULL DEFAULT \'never\',
        since     DATETIME NOT NULL,
        alerted   TINYINT(1) NOT NULL DEFAULT 0,
        CONSTRAINT fk_device_status_device FOREIGN KEY (device_id)
            REFERENCES devices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

echo "device_status ready\n";
