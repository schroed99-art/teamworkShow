<?php
/**
 * Per-device widget settings (weather + manual notices + schedule).
 *   GET ?device_id=  -> { widget: {...} }
 *   PUT/POST {device_id, weather_enabled?, weather_location?, notices_enabled?, notices_text?, schedule?}
 *            -> upsert, returns the stored row
 */
require __DIR__ . '/auth.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Widget rows hang off a device, so they inherit that device's tenant. 404 on a
 * device that does not exist, 403 on one belonging to someone else.
 */
function tw_require_device_tenant(PDO $pdo, int $deviceId): void
{
    $s = $pdo->prepare('SELECT tenant_id FROM devices WHERE id = ?');
    $s->execute([$deviceId]);
    $owner = $s->fetchColumn();
    if ($owner === false) {
        tw_json(['error' => 'device_not_found'], 404);
    }
    tw_require_tenant((int) $owner);
}

/**
 * Weekly on/off plan for the display (schools, public buildings).
 *
 *   { enabled: bool, standby: bool,
 *     days: { mon..sun: [ {from:"HH:MM", to:"HH:MM"}, ... ] } }
 *
 * Outside every window of the current weekday the screen goes dark; a day with
 * no windows stays dark all day; enabled=false = always on (the old behaviour).
 * standby = additionally try to put the display into standby (best-effort, the
 * app decides what the hardware allows). Windows are same-day (from < to), at
 * most 4 per day; anything malformed is dropped. Returns null for no plan.
 */
function tw_normalize_schedule(mixed $raw): ?string
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) {
        return null;
    }
    $isTime = static fn($t): bool => is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
    $src = is_array($raw['days'] ?? null) ? $raw['days'] : [];
    $out = ['enabled' => !empty($raw['enabled']), 'standby' => !empty($raw['standby']), 'days' => []];
    foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
        $wins = [];
        foreach ((is_array($src[$d] ?? null) ? $src[$d] : []) as $w) {
            if (count($wins) >= 4) {
                break;
            }
            $from = is_array($w) ? ($w['from'] ?? '') : '';
            $to   = is_array($w) ? ($w['to'] ?? '') : '';
            // HH:MM compares chronologically as a string.
            if ($isTime($from) && $isTime($to) && $from < $to) {
                $wins[] = ['from' => $from, 'to' => $to];
            }
        }
        $out['days'][$d] = $wins;
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES);
}

if ($method === 'GET') {
    $deviceId = (int) ($_GET['device_id'] ?? 0);
    if ($deviceId <= 0) {
        tw_json(['error' => 'device_id_required'], 422);
    }
    tw_require_device_tenant($pdo, $deviceId);
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
    tw_require_device_tenant($pdo, $deviceId);
    $exists = $pdo->prepare('SELECT id FROM widget_settings WHERE device_id = ?');
    $exists->execute([$deviceId]);
    if (!$exists->fetch()) {
        $pdo->prepare('INSERT INTO widget_settings (device_id) VALUES (?)')->execute([$deviceId]);
    }
    $set = [];
    $vals = [];
    $fontWhitelist = ['', 'serif', 'monospace', 'sans-serif-condensed', 'sans-serif-light', 'sans-serif-medium'];
    foreach (['weather_enabled', 'weather_location', 'notices_enabled', 'notices_text',
              'notices_size', 'notices_bg', 'notices_height',
              'notices_font', 'notices_color', 'notices_speed', 'schedule'] as $c) {
        if (!array_key_exists($c, $b)) {
            continue;
        }
        if ($c === 'weather_enabled' || $c === 'notices_enabled') {
            $set[] = "$c = ?";
            $vals[] = (int) (bool) $b[$c];
        } elseif ($c === 'notices_size') {
            $set[] = "$c = ?";
            $vals[] = max(8, min(80, (int) $b[$c]));         // font size in sp
        } elseif ($c === 'notices_height') {
            $set[] = "$c = ?";
            $vals[] = max(0, min(300, (int) $b[$c]));        // box height in dp; 0 = auto
        } elseif ($c === 'notices_speed') {
            $set[] = "$c = ?";
            $vals[] = max(20, min(400, (int) $b[$c]));        // scroll speed in dp/s
        } elseif ($c === 'notices_font') {
            $f = is_string($b[$c]) ? trim($b[$c]) : '';
            $set[] = "$c = ?";
            $vals[] = in_array($f, $fontWhitelist, true) ? $f : '';
        } elseif ($c === 'notices_color') {
            $col = is_string($b[$c]) ? trim($b[$c]) : '';
            $set[] = "$c = ?";
            $vals[] = preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $col)
                ? $col : '#FFFFFFFF';
        } elseif ($c === 'notices_bg') {
            // #RGB / #RRGGBB / #AARRGGBB; fall back to the classic translucent black.
            $bg = is_string($b[$c]) ? trim($b[$c]) : '';
            $set[] = "$c = ?";
            $vals[] = preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $bg)
                ? $bg : '#66000000';
        } elseif ($c === 'schedule') {
            $set[] = "$c = ?";
            $vals[] = tw_normalize_schedule($b[$c]);
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
