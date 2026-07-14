<?php
/**
 * Idempotent migration: news slides (Phase 5.4).
 *
 * A news slide is file-less like the weather interstitial — it carries its own
 * title + body. Because a slide belongs to a presentation and a presentation
 * belongs to a zone, this gives the company and the customer each their own
 * message board without any further plumbing.
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_slide_news.php
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

// 1) kind gains 'news'. MODIFY is safe to re-run: the target type is the same.
$type = (string) $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slides' AND COLUMN_NAME = 'kind'"
)->fetchColumn();

if (strpos($type, "'news'") === false) {
    $pdo->exec(
        "ALTER TABLE slides MODIFY COLUMN kind
         ENUM('media','weather','news') NOT NULL DEFAULT 'media'"
    );
    echo "slides.kind now allows 'news'\n";
} else {
    echo "slides.kind already allows 'news'\n";
}

// 2) The message itself lives on the slide.
$cols = [
    'text_title' => "VARCHAR(200) NOT NULL DEFAULT '' AFTER kind",
    'text_body'  => 'TEXT NULL DEFAULT NULL AFTER text_title',
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
