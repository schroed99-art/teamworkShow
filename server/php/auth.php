<?php
/**
 * Role-based auth for the dashboard + CRUD/admin endpoints.
 *
 * An actor's role is one of: 'admin', 'koordinator', 'betrachter'.
 * It is resolved from EITHER:
 *   - header  X-Admin-Token: <admin_password from config>  -> 'admin'  (API / curl / tests), OR
 *   - an authenticated dashboard session ($_SESSION['tw_role'], see login.php).
 * Public endpoints (playlist, media, weather, version) do NOT include this guard.
 */
require_once __DIR__ . '/db.php';

/** Current actor role, or null when not authenticated. */
function tw_role(): ?string
{
    $adminPass = (string) (tw_config()['admin_password'] ?? '');
    $token = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    if ($adminPass !== '' && $adminPass !== 'CHANGE_ME' && $token !== '' && hash_equals($adminPass, $token)) {
        return 'admin';
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $r = $_SESSION['tw_role'] ?? null;
    return (is_string($r) && $r !== '') ? $r : null;
}

/** Logged-in user id (session dashboard only; null for token/API access). */
function tw_current_user_id(): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return isset($_SESSION['tw_user_id']) ? (int) $_SESSION['tw_user_id'] : null;
}

/**
 * JSON-endpoint guard. Requires an authenticated actor whose role is in $roles
 * (any authenticated role when $roles is empty). Emits 401/403 JSON and exits
 * on failure; otherwise returns the resolved role.
 */
function tw_require_role(string ...$roles): string
{
    $role = tw_role();
    if ($role === null) {
        tw_json(['error' => 'unauthorized'], 401);
    }
    if ($roles && !in_array($role, $roles, true)) {
        tw_json(['error' => 'forbidden'], 403);
    }
    return $role;
}

/** Roles allowed to manage tenants/presentations/devices/widgets. */
function tw_require_manage(): string
{
    return tw_require_role('admin', 'koordinator');
}

/** Back-compat admin-only guard. */
function tw_require_admin(): void
{
    tw_require_role('admin');
}
