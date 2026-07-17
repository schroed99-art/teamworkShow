<?php
/**
 * Idempotent migration: per-presentation active flag.
 *
 * "active" used to be faked by routing devices.presentation_id around. With
 * multiple devices/displays that no longer fits: a presentation now carries its
 * OWN on/off state. active=1 plays where assigned; active=0 is switched off
 * (its zones show nothing / the branded empty screen). Default 1 keeps every
 * existing presentation playing.
 *
 * CLI only. Run once per backend after deploy:
 *   php /var/www/html/teamworkshow/migrate_pres_active.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

function tw_has_col(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $s->execute([$table, $col]);
    return (int) $s->fetchColumn() > 0;
}

if (!tw_has_col($pdo, 'presentations', 'active')) {
    $pdo->exec("ALTER TABLE presentations ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER name");
    echo "added presentations.active\n";
} else {
    echo "presentations.active already present\n";
}

echo "done\n";
