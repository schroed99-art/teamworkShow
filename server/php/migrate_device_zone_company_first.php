<?php
/**
 * Idempotent migration: Position der Firmen-Zone beim Splitscreen.
 *   - add devices.zone_company_first (1 = Firma zuerst = oben/links, 0 = danach = unten/rechts)
 * Default 1 = bisheriges Verhalten (Firma oben/links), daher rückwärtskompatibel.
 *
 * CLI only. Run once per backend after deploy:
 *   VM:       php /var/www/html/teamworkshow/migrate_device_zone_company_first.php
 *   All-Inkl: export TW_CONFIG=…/teamworkshow-private/app.env && php <docroot>/migrate_device_zone_company_first.php
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

if (!tw_has_col($pdo, 'devices', 'zone_company_first')) {
    $pdo->exec("ALTER TABLE devices ADD COLUMN zone_company_first TINYINT NOT NULL DEFAULT 1 AFTER zone_split");
    echo "added devices.zone_company_first\n";
} else {
    echo "devices.zone_company_first already present\n";
}
echo "done\n";
