<?php
/**
 * Streams a single media file from the media/ folder.
 *
 * Request:  GET media.php?name=<filename>
 * Rejects any name that could escape the media directory.
 */
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
