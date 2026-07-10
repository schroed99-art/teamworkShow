<?php
/** Tenant CRUD. GET list · POST create{name} · PUT update{id,name} · DELETE ?id= (cascades). */
require __DIR__ . '/auth.php';
tw_require_admin();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    tw_json(['tenants' => $pdo->query('SELECT id, name, created_at FROM tenants ORDER BY id')->fetchAll()]);
}

if ($method === 'POST') {
    $name = trim((string) (tw_body()['name'] ?? ''));
    if ($name === '') {
        tw_json(['error' => 'name_required'], 422);
    }
    $pdo->prepare('INSERT INTO tenants (name) VALUES (?)')->execute([$name]);
    tw_json(['id' => (int) $pdo->lastInsertId(), 'name' => $name], 201);
}

if ($method === 'PUT') {
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
    $id = (int) ($_GET['id'] ?? (tw_body()['id'] ?? 0));
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$id]);
    tw_json(['deleted' => $id]);
}

tw_json(['error' => 'method_not_allowed'], 405);
