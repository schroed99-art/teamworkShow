<?php
/**
 * Idempotent migration: add slides.kind ('media' | 'weather').
 * A weather slide is a file-less interstitial (forecast + clock) that sits in the
 * presentation order like any other slide, with its own position and duration_ms.
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_slide_kind.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$has = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slides' AND COLUMN_NAME = 'kind'"
)->fetchColumn();

if ($has === 0) {
    $pdo->exec("ALTER TABLE slides ADD COLUMN kind ENUM('media','weather') NOT NULL DEFAULT 'media' AFTER media_name");
    echo "added slides.kind\n";
} else {
    echo "slides.kind already present\n";
}
