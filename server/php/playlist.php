<?php
/**
 * Returns the current playlist as JSON by scanning the media/ folder.
 *
 * Response: { "items": [ { "name": string, "hash": sha256-hex, "size": int }, ... ] }
 *
 * Deploy alongside media.php on All-Inkl; put media files into ./media.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = __DIR__ . '/media';
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4'];
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
