<?php
/**
 * Lightweight live device-status endpoint for the dashboard's client-side
 * polling (overview dots + admin pills), so status updates without a page reload.
 *
 *   GET status.php -> {
 *     devices: [{id, status, seconds_since_seen}],
 *     tenants: [{id, status, device_count}]   // rollup, worst device wins
 *   }
 *
 * Read-only; any logged-in role may read it (mirrors overview visibility).
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/status_util.php';

if (tw_role() === null) {
    tw_json(['error' => 'auth'], 401);
}

$pdo = tw_db();
$rows = $pdo->query(
    'SELECT id, tenant_id, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS s FROM devices'
)->fetchAll();

$devices = [];
$byTenant = [];
foreach ($rows as $r) {
    $secs = $r['s'] === null ? null : (int) $r['s'];
    $status = tw_device_status($secs);
    $devices[] = ['id' => (int) $r['id'], 'status' => $status, 'seconds_since_seen' => $secs];
    $byTenant[(int) $r['tenant_id']][] = $status;
}

$tenants = [];
foreach ($byTenant as $tid => $statuses) {
    $tenants[] = ['id' => $tid, 'status' => tw_rollup_status($statuses), 'device_count' => count($statuses)];
}

tw_json(['devices' => $devices, 'tenants' => $tenants]);
