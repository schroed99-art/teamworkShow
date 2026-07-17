<?php
/**
 * Idempotent migration: styling for news slides.
 *
 * News slides gain the same design knobs the Laufschrift already has — font
 * family, colour and size — plus an optional background image. The background
 * reuses the existing `media_name` column (a news slide had none before), so
 * only the three style columns are new here.
 *
 * CLI only. Run once per backend after deploy:
 *   php /var/www/html/teamworkshow/migrate_slide_news_style.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

function tw_slide_col(PDO $pdo, string $column): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slides' AND COLUMN_NAME = ?"
    );
    $st->execute([$column]);
    return (int) $st->fetchColumn() > 0;
}

$cols = [
    'text_font'  => "VARCHAR(40) NOT NULL DEFAULT '' AFTER text_body",
    'text_color' => "VARCHAR(9) NOT NULL DEFAULT '' AFTER text_font",
    'text_size'  => 'SMALLINT NOT NULL DEFAULT 0 AFTER text_color',
];
foreach ($cols as $name => $ddl) {
    if (tw_slide_col($pdo, $name)) {
        echo "slides.$name already present\n";
        continue;
    }
    $pdo->exec("ALTER TABLE slides ADD COLUMN $name $ddl");
    echo "added slides.$name\n";
}

echo "done\n";
