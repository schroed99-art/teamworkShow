<?php
/**
 * Server-side weather for a device.
 *   GET weather.php?device=<pairing_code>
 * Reads the device's weather_location from widget_settings, calls OpenWeather
 * with the key from config (cached ~10 min per location). If no API key is
 * configured it returns a clear stub so the gate stays green.
 *
 * Response (live): { stub:false, enabled:bool, location, temp_c, description, icon }
 * Response (stub): { stub:true,  enabled:bool, location }
 */
require __DIR__ . '/db.php';

$device = isset($_GET['device']) ? trim((string) $_GET['device']) : '';
if ($device === '') {
    tw_json(['error' => 'device_required'], 422);
}

$pdo = tw_db();
$stmt = $pdo->prepare(
    'SELECT w.weather_enabled, w.weather_location
     FROM devices d JOIN widget_settings w ON w.device_id = d.id
     WHERE d.pairing_code = ?'
);
$stmt->execute([$device]);
$row = $stmt->fetch();
if (!$row) {
    tw_json(['error' => 'unknown_device', 'stub' => true], 404);
}

$enabled = (bool) $row['weather_enabled'];
$location = (string) $row['weather_location'];
$apiKey = (string) (tw_config()['openweather_api_key'] ?? '');

if ($apiKey === '' || $location === '') {
    tw_json(['stub' => true, 'enabled' => $enabled, 'location' => $location]);
}

// --- Cache (10 min per location) ---
$cacheFile = sys_get_temp_dir() . '/tw_weather_' . md5($location) . '.json';
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 600) {
    $cached = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        $cached['enabled'] = $enabled;
        tw_json($cached);
    }
}

$url = 'https://api.openweathermap.org/data/2.5/weather?q=' . rawurlencode($location)
    . '&units=metric&lang=de&appid=' . rawurlencode($apiKey);
$ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
$raw = @file_get_contents($url, false, $ctx);
$data = $raw !== false ? json_decode($raw, true) : null;

if (!is_array($data) || (int) ($data['cod'] ?? 0) !== 200) {
    // Upstream failure: degrade to stub rather than erroring the display.
    tw_json(['stub' => true, 'enabled' => $enabled, 'location' => $location, 'error' => 'weather_unavailable']);
}

$result = [
    'stub'        => false,
    'enabled'     => $enabled,
    'location'    => $location,
    'temp_c'      => isset($data['main']['temp']) ? round((float) $data['main']['temp'], 1) : null,
    'description' => (string) ($data['weather'][0]['description'] ?? ''),
    'icon'        => (string) ($data['weather'][0]['icon'] ?? ''),
];
@file_put_contents($cacheFile, json_encode($result));
tw_json($result);
