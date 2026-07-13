<?php
/**
 * Public self-update endpoint for the sideloaded Android app.
 *
 *   GET app_update.php -> {
 *     available:   bool,          // false when no APK has been published yet
 *     versionCode: int,           // major*10000 + minor*100 + patch (mirrors Gradle)
 *     versionName: string,        // e.g. "1.0.20"
 *     apk:         "app-release.apk",   // filename, relative to the server base URL
 *     size:        int,           // bytes
 *     sha256:      string         // lowercase hex, for integrity verification
 *   }
 *
 * The APK and its metadata are published next to the PHP files by
 * scripts/publish-apk.sh, which writes app_update.json. This endpoint just
 * serves that metadata and confirms the APK is actually present.
 * No auth guard — like playlist/media/weather/version, it is public.
 */
require_once __DIR__ . '/apk_path.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$apkName = 'app-release.apk';
$apkPath = tw_apk_path();
$metaPath = __DIR__ . '/app_update.json';

// Fallback versionName from the deployed version.php (single source of truth).
$versionName = '0.0.0';
$vfile = __DIR__ . '/version.php';
if (is_file($vfile) && preg_match("/'version'\\s*=>\\s*'([^']+)'/", (string) file_get_contents($vfile), $m)) {
    $versionName = $m[1];
}

$meta = [];
if (is_file($metaPath)) {
    $decoded = json_decode((string) file_get_contents($metaPath), true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}

$versionName = $meta['versionName'] ?? $versionName;
$parts = array_map('intval', explode('.', $versionName));
$versionCode = (int) ($meta['versionCode'] ?? (($parts[0] ?? 0) * 10000 + ($parts[1] ?? 0) * 100 + ($parts[2] ?? 0)));

if (!is_file($apkPath)) {
    echo json_encode([
        'available'   => false,
        'versionCode' => $versionCode,
        'versionName' => $versionName,
    ]);
    exit;
}

echo json_encode([
    'available'   => true,
    'versionCode' => $versionCode,
    'versionName' => $versionName,
    'apk'         => $apkName,
    'size'        => (int) ($meta['size'] ?? filesize($apkPath)),
    'sha256'      => strtolower((string) ($meta['sha256'] ?? '')),
]);
