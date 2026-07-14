<?php
/**
 * Tenant CRUD. GET list · POST create{name} · PUT update{id,name} · DELETE ?id= (cascades).
 *
 * A customer may read their own tenant (the dashboard needs its name) but never
 * create, rename or delete one — tenants are infrastructure, so mutations are
 * staff-only.
 */
require __DIR__ . '/auth.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    [$scope, $args] = tw_tenant_filter('id');
    $st = $pdo->prepare("SELECT id, name, created_at FROM tenants WHERE 1=1 $scope ORDER BY id");
    $st->execute($args);
    tw_json(['tenants' => $st->fetchAll()]);
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
    $pdo->prepare('UPDATE tenants SET name = ? WHERE id = ?')->execute([$name, $id]);
    tw_json(['id' => $id, 'name' => $name]);
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
