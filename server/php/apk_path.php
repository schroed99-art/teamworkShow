<?php
/**
 * Resolves the published APK's filesystem path. Prefers a PRIVATE directory
 * outside the web root (so the file cannot be fetched by direct URL); falls
 * back to the legacy in-webroot location for backwards compatibility.
 *
 * The APK is served only through apk.php, which requires a dashboard session
 * or a valid device pairing code. app_update.php exposes just the metadata.
 */
function tw_apk_path(): string
{
    // e.g. DOCUMENT_ROOT=/var/www/html -> /var/www/teamworkshow-apk/app-release.apk
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);
    $private = dirname($docroot) . '/teamworkshow-apk/app-release.apk';
    if (is_file($private)) {
        return $private;
    }
    return __DIR__ . '/app-release.apk'; // legacy fallback (in web root)
}
