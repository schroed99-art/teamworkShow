<?php
/**
 * Deletes a media file from media/ (POST field "name").
 *
 * Auth: requires a manage role (admin/koordinator/kunde) via dashboard session
 * or X-Admin-Token, and — for a tenant-bound customer — ownership of the file.
 * This endpoint used to be completely unauthenticated: anyone who could reach
 * the host could delete any media file.
 *
 * Rejects any name that could escape the media directory.
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/media_scope.php';
tw_require_manage();

header('Content-Type: application/json; charset=utf-8');

$name = isset($_POST['name']) ? (string) $_POST['name'] : '';
if (!tw_media_name_ok($name)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad name']);
    exit;
}

$pdo = tw_db();
tw_require_media_access($pdo, $name); // 403 + exit for another tenant's file

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

// Drop the ownership row too, so a later upload of the same name starts clean
// instead of inheriting the deleted file's tenant.
$pdo->prepare('DELETE FROM media_meta WHERE filename = ?')->execute([$name]);

echo json_encode(['ok' => true]);
