<?php
/**
 * Idempotent migration: global key/value `app_settings` table for cross-tenant
 * settings such as the help/contact card shown in the Android app's maintenance
 * menu. Seeds empty help_* keys.
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_app_settings.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS app_settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
     ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);
echo "app_settings table ready\n";

$defaults = [
    'help_company' => 'Teamwork',
    'help_phone'   => '',
    'help_email'   => '',
    'help_hours'   => '',
    'help_text'    => '',
];
$ins = $pdo->prepare('INSERT IGNORE INTO app_settings (k, v) VALUES (?, ?)');
foreach ($defaults as $k => $v) {
    $ins->execute([$k, $v]);
    echo "seed $k\n";
}
echo "done\n";
