<?php
/**
 * Per-device widget settings (weather + manual notices + schedule).
 *   GET ?device_id=  -> { widget: {...} }
 *   PUT/POST {device_id, weather_enabled?, weather_location?, notices_enabled?, notices_text?, schedule?}
 *            -> upsert, returns the stored row
 */
require __DIR__ . '/auth.php';
tw_require_admin();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $deviceId = (int) ($_GET['device_id'] ?? 0);
    if ($deviceId <= 0) {
        tw_json(['error' => 'device_id_required'], 422);
    }
    $s = $pdo->prepare('SELECT * FROM widget_settings WHERE device_id = ?');
    $s->execute([$deviceId]);
    $w = $s->fetch();
    if (!$w) {
        tw_json(['error' => 'not_found'], 404);
    }
    tw_json(['widget' => $w]);
}

if ($method === 'PUT' || $method === 'POST') {
    $b = tw_body();
    $deviceId = (int) ($b['device_id'] ?? 0);
    if ($deviceId <= 0) {
        tw_json(['error' => 'device_id_required'], 422);
    }
    $exists = $pdo->prepare('SELECT id FROM widget_settings WHERE device_id = ?');
    $exists->execute([$deviceId]);
    if (!$exists->fetch()) {
        $pdo->prepare('INSERT INTO widget_settings (device_id) VALUES (?)')->execute([$deviceId]);
    }
    $set = [];
    $vals = [];
    foreach (['weather_enabled', 'weather_location', 'notices_enabled', 'notices_text', 'schedule'] as $c) {
        if (!array_key_exists($c, $b)) {
            continue;
        }
        if ($c === 'weather_enabled' || $c === 'notices_enabled') {
            $set[] = "$c = ?";
            $vals[] = (int) (bool) $b[$c];
        } else {
            $set[] = "$c = ?";
            $vals[] = $b[$c] === null ? null : (string) $b[$c];
        }
    }
    if ($set) {
        $vals[] = $deviceId;
        $pdo->prepare('UPDATE widget_settings SET ' . implode(', ', $set) . ' WHERE device_id = ?')->execute($vals);
    }
    $s = $pdo->prepare('SELECT * FROM widget_settings WHERE device_id = ?');
    $s->execute([$deviceId]);
    tw_json(['widget' => $s->fetch()]);
}

tw_json(['error' => 'method_not_allowed'], 405);
