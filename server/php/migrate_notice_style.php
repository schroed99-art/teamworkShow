<?php
/**
 * Idempotent migration: add per-device notice-ticker styling columns to
 * `widget_settings` (font size, box background colour, box height). Mirrors the
 * previously hardcoded look (15sp, #66000000, auto height).
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_notice_style.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$cols = [
    'notices_size'   => "SMALLINT UNSIGNED NOT NULL DEFAULT 15 AFTER notices_text",
    'notices_bg'     => "VARCHAR(9) NOT NULL DEFAULT '#66000000' AFTER notices_size",
    'notices_height' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER notices_bg",
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
