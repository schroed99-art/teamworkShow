<?php
/**
 * Idempotent migration: extend the notice ticker (Laufschrift) styling with
 * font family, text colour and scroll speed.
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_notice_font.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$cols = [
    // Android family name ('' = sans-serif); see widgets.php whitelist.
    'notices_font'  => "VARCHAR(24) NOT NULL DEFAULT '' AFTER notices_height",
    // Text colour #RRGGBB or #AARRGGBB.
    'notices_color' => "VARCHAR(9) NOT NULL DEFAULT '#FFFFFFFF' AFTER notices_font",
    // Scroll speed in dp per second.
    'notices_speed' => "SMALLINT UNSIGNED NOT NULL DEFAULT 90 AFTER notices_color",
];

foreach ($cols as $name => $ddl) {
    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'widget_settings'
            AND COLUMN_NAME = '$name'"
    )->fetchColumn();
    if ($exists === 0) {
        $pdo->exec("ALTER TABLE widget_settings ADD COLUMN $name $ddl");
        echo "added widget_settings.$name\n";
    } else {
        echo "widget_settings.$name already present\n";
    }
}
echo "done\n";
