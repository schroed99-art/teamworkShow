<?php
/**
 * Idempotent migration: `media_meta` table (explicit media -> tenant assignment).
 * Seeds each file in media/ from its CURRENT usage: a file used by exactly one
 * tenant is assigned to that tenant, otherwise left unassigned (tenant_id NULL).
 * Existing rows are kept on re-run.
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_media_meta.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$pdo->exec("CREATE TABLE IF NOT EXISTS media_meta (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    filename   VARCHAR(255) NOT NULL,
    tenant_id  INT UNSIGNED NULL,
    note       VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_filename (filename),
    KEY idx_media_tenant (tenant_id),
    CONSTRAINT fk_media_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "media_meta table ready\n";

$dir = __DIR__ . '/media';
$files = is_dir($dir) ? array_values(array_filter(scandir($dir), fn ($f) => is_file("$dir/$f"))) : [];
$usage = $pdo->prepare(
    'SELECT DISTINCT p.tenant_id
       FROM slides s JOIN presentations p ON s.presentation_id = p.id
      WHERE s.media_name = ?'
);
$ins = $pdo->prepare(
    'INSERT INTO media_meta (filename, tenant_id) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE filename = filename'  // no-op: keep manual assignments
);
$seeded = 0;
$assigned = 0;
foreach ($files as $f) {
    $usage->execute([$f]);
    $tids = array_column($usage->fetchAll(), 'tenant_id');
    $tid = (count($tids) === 1) ? (int) $tids[0] : null;
    $ins->execute([$f, $tid]);
    if ($ins->rowCount() > 0) {
        $seeded++;
        if ($tid !== null) {
            $assigned++;
        }
    }
}
echo "seeded $seeded new file rows ($assigned auto-assigned by unique usage); " . count($files) . " files total\n";
