<?php
/**
 * Idempotent migration: free-form zone layouts (Phase 5.3 Vollausbau).
 *
 * The fixed two-zone split ('split', one company zone + the customer zone) stays
 * as-is. This adds devices.zone_layout: a per-display-format zone tree consumed
 * when zone_mode = 'custom'. Shape (like weather_layout.config, a JSON blob):
 *   { "v":1, "layouts": { "portrait": <Node>, "landscape": <Node>, ... } }
 * where a Node is either a split { "axis":"rows"|"cols", "children":[{size,node}] }
 * or a leaf { "zone":{ "source":"customer" | <presentation_id> } }.
 * A missing format falls back to 'single' (customer presentation full screen).
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_device_zone_layout.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

/** True when devices.<column> already exists. */
function tw_has_col(PDO $pdo, string $column): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = ?"
    );
    $st->execute([$column]);
    return (int) $st->fetchColumn() > 0;
}

if (tw_has_col($pdo, 'zone_layout')) {
    echo "devices.zone_layout already present\n";
} else {
    $pdo->exec(
        "ALTER TABLE devices
           ADD COLUMN zone_layout TEXT NULL DEFAULT NULL AFTER company_presentation_id"
    );
    echo "added devices.zone_layout\n";
}

echo "done\n";
