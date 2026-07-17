<?php
/**
 * Per-device reference photo (how the physical screen looks on site).
 *
 * Dashboard documentation only — never synced to the app. Stored in a private
 * device-photos/ dir under a random file name and served back through THIS
 * script (session-gated), so installation photos are not publicly guessable.
 *
 *   GET    ?id=<deviceId>   -> streams the image (or 404 if none)
 *   POST   id + file        -> stores/replaces the photo, returns {ok, photo}
 *   DELETE ?id=<deviceId>   -> removes the photo
 *
 * Auth mirrors devices.php: a manage role, restricted to the device's tenant
 * (a tenant-bound customer may only touch their own screens).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];
$dir = __DIR__ . '/device-photos';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? (tw_body()['id'] ?? 0));
if ($id <= 0) {
    tw_json(['error' => 'no id'], 400);
}

// The device must exist and belong to a tenant the caller may access.
$s = $pdo->prepare('SELECT tenant_id, photo FROM devices WHERE id = ?');
$s->execute([$id]);
$row = $s->fetch();
if (!$row) {
    tw_json(['error' => 'device_not_found'], 404);
}
tw_require_tenant((int) $row['tenant_id']);
$current = (string) ($row['photo'] ?? '');

if ($method === 'GET') {
    // A stored name is a bare file (no traversal); guard anyway.
    if ($current === '' || strpbrk($current, "/\\") !== false || strpos($current, '..') !== false) {
        http_response_code(404);
        exit;
    }
    $path = $dir . '/' . $current;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($current, PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=60');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($method === 'POST') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        tw_json(['ok' => false, 'error' => 'no file'], 400);
    }
    $tmp = $_FILES['file']['tmp_name'];
    // Trust the actual pixels, not the client extension.
    $info = @getimagesize($tmp);
    $ext = $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? '') : '';
    if ($ext === '') {
        tw_json(['ok' => false, 'error' => 'not_an_image'], 415);
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        tw_json(['ok' => false, 'error' => 'store_unavailable'], 500);
    }
    $name = 'dev' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        tw_json(['ok' => false, 'error' => 'store_failed'], 500);
    }
    $pdo->prepare('UPDATE devices SET photo = ? WHERE id = ?')->execute([$name, $id]);
    // Drop the previous file now that the new one is in place.
    if ($current !== '' && $current !== $name && is_file($dir . '/' . $current)) {
        @unlink($dir . '/' . $current);
    }
    tw_json(['ok' => true, 'photo' => $name]);
}

if ($method === 'DELETE') {
    if ($current !== '' && is_file($dir . '/' . $current)) {
        @unlink($dir . '/' . $current);
    }
    $pdo->prepare("UPDATE devices SET photo = '' WHERE id = ?")->execute([$id]);
    tw_json(['ok' => true]);
}

tw_json(['error' => 'method_not_allowed'], 405);
