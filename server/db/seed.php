<?php
/**
 * One-off idempotent seed. Run on the VM CLI from the app dir:  php seed.php
 * (Not meant to be web-served — the runbook scp's it in, runs it, and removes it.)
 * Only seeds when there are no tenants yet, so it is safe to re-run.
 */
require __DIR__ . '/db.php';
$pdo = tw_db();

if ((int) $pdo->query('SELECT COUNT(*) c FROM tenants')->fetch()['c'] > 0) {
    fwrite(STDOUT, "seed: already present, skipping\n");
    exit(0);
}

$pdo->beginTransaction();

$pdo->prepare('INSERT INTO tenants (name) VALUES (?)')->execute(['Teamwork Marketing']);
$tenantId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO presentations (tenant_id, name) VALUES (?, ?)')
    ->execute([$tenantId, 'Standard-Show']);
$presId = (int) $pdo->lastInsertId();

// Slides from the media folder (sorted, case-insensitive) — matches playlist folder-scan order.
$dir = __DIR__ . '/media';
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4'];
$files = [];
if (is_dir($dir)) {
    foreach (scandir($dir) as $f) {
        if (!is_file("$dir/$f")) {
            continue;
        }
        if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed, true)) {
            continue;
        }
        $files[] = $f;
    }
}
usort($files, 'strcasecmp');

$ins = $pdo->prepare('INSERT INTO slides (presentation_id, media_name, position, duration_ms) VALUES (?,?,?,?)');
$pos = 0;
foreach ($files as $f) {
    $ins->execute([$presId, $f, $pos++, 8000]);
}

$pairing = 'DEMO-01';
$pdo->prepare('INSERT INTO devices (tenant_id, presentation_id, pairing_code, name, standort, anzeige_info) VALUES (?,?,?,?,?,?)')
    ->execute([$tenantId, $presId, $pairing, 'Demo-Display', 'Foyer', 'Teamwork Marketing – Demo']);
$deviceId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO widget_settings (device_id, weather_enabled, weather_location, notices_enabled, notices_text) VALUES (?,?,?,?,?)')
    ->execute([$deviceId, 1, 'Berlin,DE', 0, '']);

$pdo->commit();
fwrite(STDOUT, "seed: tenant=$tenantId pres=$presId device=$deviceId pairing=$pairing slides=" . count($files) . "\n");
