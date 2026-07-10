<?php
/** Tenant CRUD. GET list · POST create{name} · PUT update{id,name} · DELETE ?id= (cascades). */
require __DIR__ . '/auth.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    tw_json(['tenants' => $pdo->query('SELECT id, name, projektnummer, created_at FROM tenants ORDER BY id')->fetchAll()]);
}

if ($method === 'POST') {
    $b = tw_body();
    $name = trim((string) ($b['name'] ?? ''));
    $projektnummer = trim((string) ($b['projektnummer'] ?? ''));
    if ($name === '') {
        tw_json(['error' => 'name_required'], 422);
    }
    $pdo->prepare('INSERT INTO tenants (name, projektnummer) VALUES (?, ?)')->execute([$name, $projektnummer]);
    tw_json(['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'projektnummer' => $projektnummer], 201);
}

if ($method === 'PUT') {
    $b = tw_body();
    $id = (int) ($b['id'] ?? 0);
    $name = trim((string) ($b['name'] ?? ''));
    $projektnummer = trim((string) ($b['projektnummer'] ?? ''));
    if ($id <= 0 || $name === '') {
        tw_json(['error' => 'id_and_name_required'], 422);
    }
    $pdo->prepare('UPDATE tenants SET name = ?, projektnummer = ? WHERE id = ?')->execute([$name, $projektnummer, $id]);
    tw_json(['id' => $id, 'name' => $name, 'projektnummer' => $projektnummer]);
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
