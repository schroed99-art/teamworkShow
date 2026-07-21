<?php
/**
 * Streams a single media file from the media/ folder.
 *
 * Request:  GET media.php?name=<filename>[&device=<pairing_code>]
 *
 * Access (the files carry human-readable names, so serving must be gated):
 *   - a display device presenting a valid pairing code (?device=CODE), OR
 *   - an authenticated dashboard actor (session or X-Admin-Token, for previews).
 * Everyone else gets 403. Names that could escape the media dir are rejected.
 */
require_once __DIR__ . '/auth.php';   // pulls db.php (tw_role/tw_db/tw_config)
require_once __DIR__ . '/db.php';

// --- authorise first, so an unauthorised caller learns nothing about a file ---
// The app always sends ?device=; validate the code without touching a session.
// Without a device param this is a dashboard preview -> require a logged-in actor.
$ok = false;
$code = trim((string) ($_GET['device'] ?? ''));
if ($code !== '') {
    try {
        $st = tw_db()->prepare('SELECT 1 FROM devices WHERE pairing_code = ? LIMIT 1');
        $st->execute([$code]);
        $ok = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $ok = false;
    }
} else {
    $ok = (tw_role() !== null);
}

if (!$ok) {
    http_response_code(403);
    exit;
}

$name = isset($_GET['name']) ? (string) $_GET['name'] : '';

if ($name === '' || strpbrk($name, "/\\") !== false || strpos($name, '..') !== false) {
    http_response_code(400);
    exit;
}

$allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    exit;
}

$path = __DIR__ . '/media/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mimes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'mp4'  => 'video/mp4',
];

header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
