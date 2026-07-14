<?php
/**
 * Presentation CRUD incl. ordered slides with per-slide duration.
 *   GET  ?id=          -> { presentation: { ..., slides:[{id,media_name,position,duration_ms}] } }
 *   GET  ?tenant_id=   -> { presentations: [...] }
 *   POST {tenant_id, name}                       -> create
 *   PUT  {id, name?, slides?:[{media_name,duration_ms,position?}]}  -> rename and/or replace ordered slide list
 *   DELETE ?id=        -> delete (cascades slides)
 */
require __DIR__ . '/auth.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

/** Tenant that owns $id, or null when the presentation does not exist. */
function tw_presentation_tenant(PDO $pdo, int $id): ?int
{
    $s = $pdo->prepare('SELECT tenant_id FROM presentations WHERE id = ?');
    $s->execute([$id]);
    $v = $s->fetchColumn();
    return $v === false ? null : (int) $v;
}

if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $s = $pdo->prepare('SELECT * FROM presentations WHERE id = ?');
        $s->execute([$id]);
        $p = $s->fetch();
        if (!$p) {
            tw_json(['error' => 'not_found'], 404);
        }
        tw_require_tenant((int) $p['tenant_id']);
        $ss = $pdo->prepare('SELECT id, media_name, kind, position, duration_ms FROM slides WHERE presentation_id = ? ORDER BY position, id');
        $ss->execute([$id]);
        $p['slides'] = $ss->fetchAll();
        tw_json(['presentation' => $p]);
    }
    $tenantId = (int) ($_GET['tenant_id'] ?? 0);
    if ($tenantId > 0) {
        tw_require_tenant($tenantId);
        $s = $pdo->prepare('SELECT * FROM presentations WHERE tenant_id = ? ORDER BY id');
        $s->execute([$tenantId]);
        $rows = $s->fetchAll();
    } else {
        [$scope, $args] = tw_tenant_filter('tenant_id');
        $s = $pdo->prepare("SELECT * FROM presentations WHERE 1=1 $scope ORDER BY id");
        $s->execute($args);
        $rows = $s->fetchAll();
    }
    tw_json(['presentations' => $rows]);
}

if ($method === 'POST') {
    $b = tw_body();
    // A customer creates only inside their own tenant, whatever the body claims.
    $tenantId = (int) tw_owning_tenant($b['tenant_id'] ?? 0);
    $name = trim((string) ($b['name'] ?? ''));
    if ($tenantId <= 0 || $name === '') {
        tw_json(['error' => 'tenant_id_and_name_required'], 422);
    }
    $pdo->prepare('INSERT INTO presentations (tenant_id, name) VALUES (?, ?)')->execute([$tenantId, $name]);
    tw_json(['id' => (int) $pdo->lastInsertId(), 'name' => $name], 201);
}

if ($method === 'PUT') {
    $b = tw_body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $owner = tw_presentation_tenant($pdo, $id);
    if ($owner === null) {
        tw_json(['error' => 'not_found'], 404);
    }
    tw_require_tenant($owner);
    if (array_key_exists('name', $b)) {
        $newName = trim((string) $b['name']);
        if ($newName === '') {
            tw_json(['error' => 'name_required'], 422);
        }
        $pdo->prepare('UPDATE presentations SET name = ? WHERE id = ?')->execute([$newName, $id]);
    }
    // Toggle which presentation the tenant's device(s) show. active=true routes all
    // of the tenant's devices to this presentation; active=false switches off only
    // the devices currently showing it (-> NULL = folder fallback).
    if (array_key_exists('active', $b)) {
        if ($b['active']) {
            $pdo->prepare('UPDATE devices SET presentation_id = ? WHERE tenant_id = ?')
                ->execute([$id, $owner]);
        } else {
            $pdo->prepare('UPDATE devices SET presentation_id = NULL WHERE tenant_id = ? AND presentation_id = ?')
                ->execute([$owner, $id]);
        }
    }
    if (array_key_exists('slides', $b) && is_array($b['slides'])) {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM slides WHERE presentation_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO slides (presentation_id, media_name, kind, position, duration_ms) VALUES (?,?,?,?,?)');
        $pos = 0;
        foreach ($b['slides'] as $sl) {
            $kind = ($sl['kind'] ?? 'media') === 'weather' ? 'weather' : 'media';
            $mn = trim((string) ($sl['media_name'] ?? ''));
            // Media slides need a file; weather slides are file-less interstitials.
            if ($kind === 'media' && $mn === '') {
                continue;
            }
            if ($kind === 'weather') {
                $mn = '';
            }
            $dur = (int) ($sl['duration_ms'] ?? 8000);
            if ($dur < 250) {
                $dur = 250;
            }
            $position = array_key_exists('position', $sl) ? (int) $sl['position'] : $pos;
            $ins->execute([$id, $mn, $kind, $position, $dur]);
            $pos++;
        }
        $pdo->commit();
    }
    tw_json(['id' => $id, 'updated' => true]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? (tw_body()['id'] ?? 0));
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $owner = tw_presentation_tenant($pdo, $id);
    if ($owner === null) {
        tw_json(['error' => 'not_found'], 404);
    }
    tw_require_tenant($owner);
    $pdo->prepare('DELETE FROM presentations WHERE id = ?')->execute([$id]);
    tw_json(['deleted' => $id]);
}

tw_json(['error' => 'method_not_allowed'], 405);
