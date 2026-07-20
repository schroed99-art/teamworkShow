<?php
/**
 * Tenant CRUD. GET list · POST create{name} · PUT update{id,name} · DELETE ?id= (cascades).
 *
 * A customer may read their own tenant (the dashboard needs its name) but never
 * create, rename or delete one — tenants are infrastructure, so mutations are
 * staff-only.
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/status_util.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    [$scope, $args] = tw_tenant_filter('id');
    $st = $pdo->prepare("SELECT id, name, contact_company, contact_address, created_at FROM tenants WHERE 1=1 $scope ORDER BY id");
    $st->execute($args);
    $tenants = $st->fetchAll();

    // Roll each tenant's device statuses up to one badge for the sidebar list:
    // green if any online, amber if any alarming, grey if offline, none if empty.
    $ds = $pdo->query('SELECT tenant_id, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS secs FROM devices');
    $byTenant = [];
    foreach ($ds as $d) {
        $secs = $d['secs'] === null ? null : (int) $d['secs'];
        $byTenant[(int) $d['tenant_id']][] = tw_device_status($secs);
    }
    foreach ($tenants as &$t) {
        $sts = $byTenant[(int) $t['id']] ?? [];
        $t['devices_total'] = count($sts);
        $t['devices_online'] = count(array_filter($sts, static fn($s) => $s === 'online'));
        $t['status'] = tw_rollup_status($sts);
    }
    unset($t);

    tw_json(['tenants' => $tenants]);
}

if ($method === 'POST') {
    tw_require_staff();
    $name = trim((string) (tw_body()['name'] ?? ''));
    if ($name === '') {
        tw_json(['error' => 'name_required'], 422);
    }
    $pdo->prepare('INSERT INTO tenants (name) VALUES (?)')->execute([$name]);
    tw_json(['id' => (int) $pdo->lastInsertId(), 'name' => $name], 201);
}

if ($method === 'PUT') {
    tw_require_staff();
    $b = tw_body();
    $id = (int) ($b['id'] ?? 0);
    $name = trim((string) ($b['name'] ?? ''));
    if ($id <= 0 || $name === '') {
        tw_json(['error' => 'id_and_name_required'], 422);
    }
    // Kundenstammdaten (Firmenname + Anschrift) sind optional — sie werden auf der
    // Leer-Ansicht des Geräts angezeigt, wenn (noch) keine Präsentation läuft.
    $company = mb_substr(trim((string) ($b['contact_company'] ?? '')), 0, 200);
    $address = mb_substr(trim((string) ($b['contact_address'] ?? '')), 0, 500);
    $pdo->prepare('UPDATE tenants SET name = ?, contact_company = ?, contact_address = ? WHERE id = ?')
        ->execute([$name, $company, $address, $id]);
    tw_json(['id' => $id, 'name' => $name, 'contact_company' => $company, 'contact_address' => $address]);
}

if ($method === 'DELETE') {
    tw_require_staff();
    $id = (int) ($_GET['id'] ?? (tw_body()['id'] ?? 0));
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$id]);
    tw_json(['deleted' => $id]);
}

tw_json(['error' => 'method_not_allowed'], 405);
