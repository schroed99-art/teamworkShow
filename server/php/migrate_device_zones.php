<?php
/**
 * Idempotent migration: split the screen into two zones (Phase 5.3).
 *
 * A device is either 'single' (one full-screen slideshow — everything so far) or
 * 'split': a company zone (staff-authored, company_presentation_id) and a customer
 * zone (the existing presentation_id, which is the only one a customer may set).
 * zone_axis says whether the two sit above each other or side by side; zone_split
 * is the company zone's share in percent.
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_device_zones.php
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

$cols = [
    'zone_mode'  => "VARCHAR(8) NOT NULL DEFAULT 'single' AFTER display_format",
    'zone_axis'  => "VARCHAR(8) NOT NULL DEFAULT 'rows' AFTER zone_mode",
    'zone_split' => 'TINYINT UNSIGNED NOT NULL DEFAULT 70 AFTER zone_axis',
    'company_presentation_id' => 'INT UNSIGNED NULL DEFAULT NULL AFTER zone_split',
];

foreach ($cols as $name => $ddl) {
    if (tw_has_col($pdo, $name)) {
        echo "devices.$name already present\n";
        continue;
    }
    $pdo->exec("ALTER TABLE devices ADD COLUMN $name $ddl");
    echo "added devices.$name\n";
}

// The company zone points at a presentation like the customer zone does; when that
// presentation is deleted the zone simply falls empty rather than dangling.
$fk = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices'
        AND CONSTRAINT_NAME = 'fk_devices_company_presentation'"
)->fetchColumn();

if ($fk === 0) {
    $pdo->exec(
        'ALTER TABLE devices
           ADD KEY idx_devices_company_presentation (company_presentation_id),
           ADD CONSTRAINT fk_devices_company_presentation FOREIGN KEY (company_presentation_id)
               REFERENCES presentations (id) ON DELETE SET NULL'
    );
    echo "added fk_devices_company_presentation\n";
} else {
    echo "fk_devices_company_presentation already present\n";
}

echo "done\n";
