<?php
/**
 * Idempotent migration: Projektnummer belongs to a DEVICE (not the tenant).
 *   - add devices.projektnummer if missing
 *   - drop tenants.projektnummer if present (was added in v1.0.10 by mistake; empty)
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_device_projektnr.php
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

if (!tw_has_col($pdo, 'devices', 'projektnummer')) {
    $pdo->exec("ALTER TABLE devices ADD COLUMN projektnummer VARCHAR(32) NOT NULL DEFAULT '' AFTER standort");
    echo "added devices.projektnummer\n";
} else {
    echo "devices.projektnummer already present\n";
}

if (tw_has_col($pdo, 'tenants', 'projektnummer')) {
    $pdo->exec("ALTER TABLE tenants DROP COLUMN projektnummer");
    echo "dropped stray tenants.projektnummer\n";
} else {
    echo "tenants.projektnummer already absent\n";
}
