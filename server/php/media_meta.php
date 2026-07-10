<?php
/**
 * Media <-> tenant assignment (admin + koordinator).
 *   GET  -> { items:[{filename, tenant_id, tenant_name, note}],
 *            tenants:[{id,name}],
 *            standorte:[{tenant_id, standort}] }     // for the Standort filter
 *   PUT  {filename, tenant_id|null, note?}  -> upsert one file's assignment
 *
 * Standort is not stored per file; the UI derives it as "media whose assigned
 * tenant has a device at that Standort" using the standorte map above.
 */
require __DIR__ . '/auth.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $items = $pdo->query(
        'SELECT m.filename, m.tenant_id, t.name AS tenant_name, m.note
           FROM media_meta m LEFT JOIN tenants t ON t.id = m.tenant_id
          ORDER BY m.filename'
    )->fetchAll();
    foreach ($items as &$it) {
        $it['tenant_id'] = $it['tenant_id'] !== null ? (int) $it['tenant_id'] : null;
    }
    unset($it);
    $tenants = $pdo->query('SELECT id, name FROM tenants ORDER BY id')->fetchAll();
    foreach ($tenants as &$t) {
        $t['id'] = (int) $t['id'];
    }
    unset($t);
    $standorte = $pdo->query(
        "SELECT DISTINCT tenant_id, standort FROM devices WHERE standort <> '' ORDER BY standort"
    )->fetchAll();
    foreach ($standorte as &$s) {
        $s['tenant_id'] = (int) $s['tenant_id'];
    }
    unset($s);
    // Projektnummer lives on the device; expose per-tenant so the pool can search by it.
    $projekte = $pdo->query(
        "SELECT DISTINCT tenant_id, projektnummer FROM devices WHERE projektnummer <> '' ORDER BY projektnummer"
    )->fetchAll();
    foreach ($projekte as &$p) {
        $p['tenant_id'] = (int) $p['tenant_id'];
    }
    unset($p);
    tw_json(['items' => $items, 'tenants' => $tenants, 'standorte' => $standorte, 'projekte' => $projekte]);
}

if ($method === 'PUT') {
    $b = tw_body();
    $filename = (string) ($b['filename'] ?? '');
    if ($filename === '' || strpbrk($filename, "/\\") !== false || strpos($filename, '..') !== false) {
        tw_json(['error' => 'bad_filename'], 422);
    }
    $tenantId = null;
    if (array_key_exists('tenant_id', $b) && $b['tenant_id'] !== null && $b['tenant_id'] !== '') {
        $tenantId = (int) $b['tenant_id'];
        $chk = $pdo->prepare('SELECT id FROM tenants WHERE id = ?');
        $chk->execute([$tenantId]);
        if (!$chk->fetch()) {
            tw_json(['error' => 'tenant_not_found'], 422);
        }
    }
    $note = (string) ($b['note'] ?? '');
    $pdo->prepare(
        'INSERT INTO media_meta (filename, tenant_id, note) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), note = VALUES(note)'
    )->execute([$filename, $tenantId, $note]);
    tw_json(['ok' => true, 'filename' => $filename, 'tenant_id' => $tenantId]);
}

tw_json(['error' => 'method_not_allowed'], 405);
