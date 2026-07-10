<?php
/**
 * Idempotent migration: add tenants.projektnummer (Projekt-/Mandantennummer).
 * One combined field — project number and tenant number are the same value.
 * Existing tenants get an empty string; fill it via the dashboard.
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_tenant_projektnr.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$has = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'projektnummer'"
)->fetchColumn();

if ($has === 0) {
    $pdo->exec("ALTER TABLE tenants ADD COLUMN projektnummer VARCHAR(32) NOT NULL DEFAULT '' AFTER name");
    echo "added tenants.projektnummer\n";
} else {
    echo "tenants.projektnummer already present\n";
}
