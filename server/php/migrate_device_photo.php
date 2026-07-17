<?php
/**
 * Idempotent migration: a reference photo per device.
 *
 * A device may carry one photo of how the physical screen looks on site (shot
 * with a phone camera). It is dashboard documentation only — it never syncs to
 * the app — so it is a single file name, stored in a private device-photos/ dir.
 *
 * CLI only. Run once per backend after deploy:
 *   php /var/www/html/teamworkshow/migrate_device_photo.php
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

if (!tw_has_col($pdo, 'devices', 'photo')) {
    $pdo->exec("ALTER TABLE devices ADD COLUMN photo VARCHAR(255) NOT NULL DEFAULT '' AFTER anzeige_info");
    echo "added devices.photo\n";
} else {
    echo "devices.photo already present\n";
}

echo "done\n";
