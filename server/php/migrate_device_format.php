<?php
/**
 * Idempotent migration: add a per-device display format so a device can render
 * as portrait signage, a phone, landscape/TV or a tablet. Drives the app's
 * runtime orientation + which layout/dimen resources it loads.
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_device_format.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$name = 'display_format';
$ddl  = "VARCHAR(16) NOT NULL DEFAULT 'portrait' AFTER anzeige_info";

$exists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'devices'
        AND COLUMN_NAME = '$name'"
)->fetchColumn();

if ($exists === 0) {
    $pdo->exec("ALTER TABLE devices ADD COLUMN $name $ddl");
    echo "added devices.$name\n";
} else {
    echo "devices.$name already present\n";
}
echo "done\n";
