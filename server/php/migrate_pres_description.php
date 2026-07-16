<?php
/**
 * Idempotent migration: short description per presentation (shown in the
 * dashboard's presentation list under the name, next to the first-slide thumb).
 *   - add presentations.description if missing
 *
 * CLI only. Run once per backend after deploy:
 *   VM:       php /var/www/html/teamworkshow/migrate_pres_description.php
 *   All-Inkl: export TW_CONFIG=…/teamworkshow-private/app.env && php <docroot>/migrate_pres_description.php
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

if (!tw_has_col($pdo, 'presentations', 'description')) {
    $pdo->exec("ALTER TABLE presentations ADD COLUMN description VARCHAR(300) NOT NULL DEFAULT '' AFTER name");
    echo "added presentations.description\n";
} else {
    echo "presentations.description already present\n";
}
echo "done\n";
