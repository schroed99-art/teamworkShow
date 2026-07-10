<?php
/**
 * Playlist endpoint.
 *
 *  - GET playlist.php                     -> folder scan (backwards compatible):
 *        { "items": [ { name, hash, size }, ... ] }
 *  - GET playlist.php?device=<pairing>    -> device-specific, DB-backed:
 *        { "items": [ { name, hash, size, position, duration_ms }, ... ],
 *          "device": { pairing_code, name, standort, anzeige_info },
 *          "tenant": { id, name },
 *          "widgets": { weather_enabled, weather_location, notices_enabled, notices_text, schedule } }
 *
 * Slides whose media file is missing on disk are skipped so the app's hash sync stays consistent.
 */
require __DIR__ . '/db.php';

$dir = __DIR__ . '/media';
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4'];

/** Return [hash, size] for a media file, or null when it is not on disk. */
function tw_media_meta(string $dir, string $name): ?array
{
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        return null;
    }
    return ['hash' => hash_file('sha256', $path), 'size' => filesize($path)];
}

$device = isset($_GET['device']) ? trim((string) $_GET['device']) : '';

// --- Folder-scan fallback (no device): unchanged legacy contract. ---
if ($device === '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $items = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'hash' => hash_file('sha256', $path),
                'size' => filesize($path),
            ];
        }
    }
    usort($items, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
    echo json_encode(['items' => $items], JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Device-specific playlist. ---
try {
    $pdo = tw_db();
    $stmt = $pdo->prepare(
        'SELECT d.id, d.pairing_code, d.name, d.standort, d.anzeige_info, d.presentation_id,
                t.id AS tenant_id, t.name AS tenant_name
         FROM devices d JOIN tenants t ON t.id = d.tenant_id
         WHERE d.pairing_code = ?'
    );
    $stmt->execute([$device]);
    $dev = $stmt->fetch();

    if (!$dev) {
        tw_json(['error' => 'unknown_device', 'items' => []], 404);
    }

    $pdo->prepare('UPDATE devices SET last_seen = NOW() WHERE id = ?')->execute([$dev['id']]);

    $items = [];
    $hasWeather = false;
    if (!empty($dev['presentation_id'])) {
        $ss = $pdo->prepare(
            'SELECT media_name, kind, position, duration_ms FROM slides
             WHERE presentation_id = ? ORDER BY position, id'
        );
        $ss->execute([$dev['presentation_id']]);
        foreach ($ss as $row) {
            // Weather interstitial: file-less slide, kept in order with its duration.
            if (($row['kind'] ?? 'media') === 'weather') {
                $hasWeather = true;
                $items[] = [
                    'name'        => '',
                    'kind'        => 'weather',
                    'position'    => (int) $row['position'],
                    'duration_ms' => (int) $row['duration_ms'],
                ];
                continue;
            }
            $meta = tw_media_meta($dir, $row['media_name']);
            if ($meta === null) {
                continue;
            }
            $items[] = [
                'name'        => $row['media_name'],
                'kind'        => 'media',
                'hash'        => $meta['hash'],
                'size'        => $meta['size'],
                'position'    => (int) $row['position'],
                'duration_ms' => (int) $row['duration_ms'],
            ];
        }
    }

    $ws = $pdo->prepare(
        'SELECT weather_enabled, weather_location, notices_enabled, notices_text,
                notices_size, notices_bg, notices_height, schedule
         FROM widget_settings WHERE device_id = ?'
    );
    $ws->execute([$dev['id']]);
    $w = $ws->fetch() ?: [];

    // Global weather-interstitial template (shared). Delivered raw; the app renders it.
    // The background is a pool file downloaded separately from the slide set so it never
    // rotates as its own slide — only hinted when a weather slide is actually present.
    $weatherLayout = null;
    $weatherAsset = null;
    try {
        $lc = $pdo->query('SELECT config FROM weather_layout WHERE id = 1')->fetchColumn();
        $cfg = is_string($lc) ? json_decode($lc, true) : null;
        if (is_array($cfg)) {
            $weatherLayout = $cfg;
            $bg = is_string($cfg['background'] ?? null) ? $cfg['background'] : '';
            if ($hasWeather && $bg !== '' && strpbrk($bg, "/\\") === false && strpos($bg, '..') === false) {
                $meta = tw_media_meta($dir, $bg);
                if ($meta !== null) {
                    $weatherAsset = ['name' => $bg, 'hash' => $meta['hash'], 'size' => $meta['size']];
                }
            }
        }
    } catch (Throwable $e) {
        // weather_layout table may not exist yet (pre-migration): degrade silently.
    }

    tw_json([
        'items'  => $items,
        'device' => [
            'pairing_code' => $dev['pairing_code'],
            'name'         => $dev['name'],
            'standort'     => $dev['standort'],
            'anzeige_info' => $dev['anzeige_info'],
        ],
        'tenant' => [
            'id'   => (int) $dev['tenant_id'],
            'name' => $dev['tenant_name'],
        ],
        'widgets' => [
            'weather_enabled'  => (bool) ($w['weather_enabled'] ?? false),
            'weather_location' => (string) ($w['weather_location'] ?? ''),
            'notices_enabled'  => (bool) ($w['notices_enabled'] ?? false),
            'notices_text'     => (string) ($w['notices_text'] ?? ''),
            'notices_size'     => (int) ($w['notices_size'] ?? 15),
            'notices_bg'       => (string) ($w['notices_bg'] ?? '#66000000'),
            'notices_height'   => (int) ($w['notices_height'] ?? 0),
            'schedule'         => $w['schedule'] ?? null,
        ],
        'weather_layout' => $weatherLayout,
        'weather_asset'  => $weatherAsset,
    ]);
} catch (Throwable $e) {
    tw_json(['error' => 'server_error', 'items' => []], 500);
}
