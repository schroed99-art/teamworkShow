<?php
/**
 * Idempotent migration: per-account opt-in for device-offline alarm e-mails.
 *
 *   users.notify_device_alarm  1 (default) -> this account receives device
 *                                             offline/alarm notifications
 *                              0            -> excluded from those mails
 *
 * Only admins are ever alarm recipients (device_monitor.php), so the flag is
 * meaningful for admin accounts; it is harmless on other roles. Existing users
 * default to 1 so current behaviour (every active admin gets alarmed) is kept
 * until someone is deliberately switched off.
 *
 * CLI only. Run once per server after deploy:
 *   php /var/www/html/teamworkshow/migrate_user_alarm_notify.php
 *   (All-Inkl: TW_CONFIG=/…/teamworkshow-private/app.env php …/migrate_user_alarm_notify.php)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$hasCol = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'notify_device_alarm'"
)->fetchColumn();

if ($hasCol === 0) {
    $pdo->exec(
        "ALTER TABLE users
           ADD COLUMN notify_device_alarm TINYINT(1) NOT NULL DEFAULT 1 AFTER active"
    );
    echo "added users.notify_device_alarm\n";
} else {
    echo "users.notify_device_alarm already present\n";
}

echo "done\n";
