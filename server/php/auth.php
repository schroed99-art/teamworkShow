<?php
/**
 * Admin guard for the CRUD/admin endpoints.
 * Access is granted by EITHER:
 *   - header  X-Admin-Token: <admin_password from config>   (API / curl), OR
 *   - an authenticated dashboard session ($_SESSION['tw_admin'], see login.php).
 * Public endpoints (playlist, media, version) do NOT include this guard.
 */
require_once __DIR__ . '/db.php';

function tw_require_admin(): void
{
    $adminPass = (string) (tw_config()['admin_password'] ?? '');
    $token = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    if ($adminPass !== '' && $adminPass !== 'CHANGE_ME' && hash_equals($adminPass, $token)) {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!empty($_SESSION['tw_admin'])) {
        return;
    }
    tw_json(['error' => 'unauthorized'], 401);
}
