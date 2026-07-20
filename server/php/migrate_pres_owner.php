<?php
/**
 * Idempotent migration: Präsentation gehört Kunde oder Teamwork.
 *   - add presentations.is_company (0 = Kunden-Inhalt, 1 = Teamwork/Firmen-Inhalt)
 * Default 0 = Kunden-Inhalt (bestehende Präsentationen bleiben beim Kunden sichtbar);
 * Teamwork markiert eigene Inhalte danach per Schalter als Teamwork-Inhalt.
 *
 * CLI only:
 *   VM:       php /var/www/html/teamworkshow/migrate_pres_owner.php
 *   All-Inkl: export TW_CONFIG=…/teamworkshow-private/app.env && php <docroot>/migrate_pres_owner.php
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

if (!tw_has_col($pdo, 'presentations', 'is_company')) {
    $pdo->exec("ALTER TABLE presentations ADD COLUMN is_company TINYINT NOT NULL DEFAULT 0 AFTER active");
    echo "added presentations.is_company\n";
} else {
    echo "presentations.is_company already present\n";
}
echo "done\n";
