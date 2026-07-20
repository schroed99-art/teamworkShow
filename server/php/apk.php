<?php
/**
 * Gated APK delivery. Streams the published signed APK only to:
 *   - an authenticated dashboard actor (session or X-Admin-Token), OR
 *   - a device presenting a valid pairing code: apk.php?device=CODE
 *     (so the in-app self-update keeps working without a login).
 *
 * Everyone else gets 403. The file itself lives outside the web root
 * (see apk_path.php), so there is no direct download URL.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/apk_path.php';
require_once __DIR__ . '/audit_log.php';

$code = trim((string) ($_GET['device'] ?? ''));
$ok = (tw_role() !== null);

if (!$ok && $code !== '') {
    try {
        $st = tw_db()->prepare('SELECT COUNT(*) FROM devices WHERE pairing_code = ?');
        $st->execute([$code]);
        $ok = ((int) $st->fetchColumn() > 0);
    } catch (Throwable $e) {
        $ok = false;
    }
}

if (!$ok) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$apk = tw_apk_path();
if (!is_file($apk)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Keine App veröffentlicht.';
    exit;
}

// Attribute a real device self-update to that device (staff/token downloads are
// not device updates and carry no code, so they are not logged here).
if ($code !== '') {
    $ver = '';
    $vfile = __DIR__ . '/version.php';
    if (is_file($vfile) && preg_match("/'version'\\s*=>\\s*'([^']+)'/", (string) file_get_contents($vfile), $vm)) {
        $ver = $vm[1];
    }
    tw_audit('update', 'app_update_download', [
        'device_code' => $code,
        'detail'      => $ver !== '' ? 'APK v' . $ver : 'APK',
    ]);
}

header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="teamworkshow.apk"');
header('Content-Length: ' . filesize($apk));
header('Cache-Control: no-store');
readfile($apk);
