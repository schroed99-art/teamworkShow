<?php
/**
 * Accepts media uploads (multipart POST, field "file", one or many) and stores
 * them in media/. Same filename overwrites (= exchange); new name adds.
 *
 * Auth: requires a manage role (admin/koordinator) via dashboard session or
 * X-Admin-Token. Used by the Medienpool and the slide editor.
 */
require_once __DIR__ . '/auth.php';
tw_require_manage();

header('Content-Type: application/json; charset=utf-8');

$allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4'];
$dir = __DIR__ . '/media';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no file']);
    exit;
}

// Normalise to a list whether one or many files were sent.
$files = $_FILES['file'];
$names = is_array($files['name']) ? $files['name'] : [$files['name']];
$tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
$errs  = is_array($files['error']) ? $files['error'] : [$files['error']];

$saved = [];
$errors = [];
for ($i = 0; $i < count($names); $i++) {
    if ($errs[$i] !== UPLOAD_ERR_OK) {
        $errors[] = ['name' => $names[$i], 'error' => 'upload_error_' . $errs[$i]];
        continue;
    }
    $name = basename($names[$i]);
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || strpos($name, '..') !== false || !in_array($ext, $allowed, true)) {
        $errors[] = ['name' => $names[$i], 'error' => 'invalid'];
        continue;
    }
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmps[$i], $dest)) {
        $errors[] = ['name' => $name, 'error' => 'save_failed'];
        continue;
    }
    @chmod($dest, 0664);
    $saved[] = $name;
}

echo json_encode(['ok' => count($errors) === 0, 'saved' => $saved, 'errors' => $errors]);
