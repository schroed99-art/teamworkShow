<?php
/**
 * Device CRUD.
 *   GET    ?tenant_id=  (or all) -> { devices: [...] }
 *   POST   {tenant_id, name?, standort?, anzeige_info?, presentation_id?, pairing_code?}
 *          -> creates a device (+ default widget row); pairing_code auto-generated if omitted
 *   PUT    {id, name?|standort?|anzeige_info?|presentation_id?|tenant_id?}
 *   DELETE ?id= (cascades slides via presentation? no — cascades widget_settings)
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/status_util.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

/** Allowed device display formats; anything else falls back to portrait. */
const TW_DISPLAY_FORMATS = ['portrait', 'phone', 'landscape', 'tablet'];

function tw_display_format(mixed $v): string
{
    $v = strtolower(trim((string) $v));
    return in_array($v, TW_DISPLAY_FORMATS, true) ? $v : 'portrait';
}

function tw_gen_pairing(PDO $pdo): string
{
    for ($i = 0; $i < 25; $i++) {
        $hex = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
        $code = substr($hex, 0, 3) . '-' . substr($hex, 3, 3);
        $s = $pdo->prepare('SELECT 1 FROM devices WHERE pairing_code = ?');
        $s->execute([$code]);
        if (!$s->fetch()) {
            return $code;
        }
    }
    throw new RuntimeException('could not generate a unique pairing code');
}

if ($method === 'GET') {
    $tenantId = (int) ($_GET['tenant_id'] ?? 0);
    $sel = 'SELECT d.*, TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) AS seconds_since_seen FROM devices d';
    if ($tenantId > 0) {
        $s = $pdo->prepare($sel . ' WHERE d.tenant_id = ? ORDER BY d.id');
        $s->execute([$tenantId]);
        $rows = $s->fetchAll();
    } else {
        $rows = $pdo->query($sel . ' ORDER BY d.id')->fetchAll();
    }
    foreach ($rows as &$r) {
        $secs = $r['seconds_since_seen'] === null ? null : (int) $r['seconds_since_seen'];
        $r['seconds_since_seen'] = $secs;
        $r['status'] = tw_device_status($secs);
    }
    unset($r);
    tw_json(['devices' => $rows]);
}

if ($method === 'POST') {
    $b = tw_body();
    $tenantId = (int) ($b['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        tw_json(['error' => 'tenant_id_required'], 422);
    }
    $code = trim((string) ($b['pairing_code'] ?? ''));
    if ($code === '') {
        $code = tw_gen_pairing($pdo);
    }
    $presId = !empty($b['presentation_id']) ? (int) $b['presentation_id'] : null;
    try {
        $pdo->prepare(
            'INSERT INTO devices (tenant_id, presentation_id, pairing_code, name, standort, projektnummer, anzeige_info, display_format)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $tenantId,
            $presId,
            $code,
            (string) ($b['name'] ?? ''),
            (string) ($b['standort'] ?? ''),
            (string) ($b['projektnummer'] ?? ''),
            (string) ($b['anzeige_info'] ?? ''),
            tw_display_format($b['display_format'] ?? 'portrait'),
        ]);
    } catch (PDOException $e) {
        tw_json(['error' => 'pairing_taken'], 409);
    }
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO widget_settings (device_id) VALUES (?)')->execute([$id]);
    tw_json(['id' => $id, 'pairing_code' => $code], 201);
}

if ($method === 'PUT') {
    $b = tw_body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $set = [];
    $vals = [];
    foreach (['name', 'standort', 'projektnummer', 'anzeige_info'] as $f) {
        if (array_key_exists($f, $b)) {
            $set[] = "$f = ?";
            $vals[] = (string) $b[$f];
        }
    }
    if (array_key_exists('presentation_id', $b)) {
        $set[] = 'presentation_id = ?';
        $vals[] = !empty($b['presentation_id']) ? (int) $b['presentation_id'] : null;
    }
    if (array_key_exists('tenant_id', $b)) {
        $set[] = 'tenant_id = ?';
        $vals[] = (int) $b['tenant_id'];
    }
    if (array_key_exists('display_format', $b)) {
        $set[] = 'display_format = ?';
        $vals[] = tw_display_format($b['display_format']);
    }
    if (!$set) {
        tw_json(['error' => 'nothing_to_update'], 422);
    }
    $vals[] = $id;
    $pdo->prepare('UPDATE devices SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    tw_json(['id' => $id, 'updated' => true]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? (tw_body()['id'] ?? 0));
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $pdo->prepare('DELETE FROM devices WHERE id = ?')->execute([$id]);
    tw_json(['deleted' => $id]);
}

tw_json(['error' => 'method_not_allowed'], 405);
