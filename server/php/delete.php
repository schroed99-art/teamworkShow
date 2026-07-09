<?php
/**
 * Deletes a media file from media/ (POST field "name").
 * Rejects any name that could escape the media directory.
 */
header('Content-Type: application/json; charset=utf-8');

$name = isset($_POST['name']) ? (string) $_POST['name'] : '';
if ($name === '' || strpbrk($name, "/\\") !== false || strpos($name, '..') !== false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad name']);
    exit;
}

$path = __DIR__ . '/media/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not found']);
    exit;
}
if (!unlink($path)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'delete failed']);
    exit;
}

echo json_encode(['ok' => true]);
