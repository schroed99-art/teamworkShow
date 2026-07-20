<?php
/**
 * Idempotent migration: Kundenstammdaten je Mandant (Firmenname + Anschrift).
 * Werden im Dashboard unter Einstellungen gepflegt und auf der Leer-Ansicht des
 * Geräts angezeigt ("<Firma>, <Anschrift> hat noch keine Präsentation hinterlegt").
 *   - add tenants.contact_company if missing
 *   - add tenants.contact_address if missing
 *
 * CLI only. Run once per backend after deploy:
 *   VM:       php /var/www/html/teamworkshow/migrate_tenant_contact.php
 *   All-Inkl: export TW_CONFIG=…/teamworkshow-private/app.env && php <docroot>/migrate_tenant_contact.php
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

if (!tw_has_col($pdo, 'tenants', 'contact_company')) {
    $pdo->exec("ALTER TABLE tenants ADD COLUMN contact_company VARCHAR(200) NOT NULL DEFAULT '' AFTER name");
    echo "added tenants.contact_company\n";
} else {
    echo "tenants.contact_company already present\n";
}

if (!tw_has_col($pdo, 'tenants', 'contact_address')) {
    $pdo->exec("ALTER TABLE tenants ADD COLUMN contact_address VARCHAR(500) NOT NULL DEFAULT '' AFTER contact_company");
    echo "added tenants.contact_address\n";
} else {
    echo "tenants.contact_address already present\n";
}
echo "done\n";
