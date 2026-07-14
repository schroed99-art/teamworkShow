<?php
/**
 * Role-based auth for the dashboard + CRUD/admin endpoints.
 *
 * An actor's role is one of: 'admin', 'koordinator', 'betrachter', 'kunde'.
 * It is resolved from EITHER:
 *   - header  X-Admin-Token: <admin_password from config>  -> 'admin'  (API / curl / tests), OR
 *   - an authenticated dashboard session ($_SESSION['tw_role'], see login.php).
 * Public endpoints (playlist, media, weather, version) do NOT include this guard.
 *
 * TENANT SCOPING (Phase 5.2)
 * A user may be bound to a single tenant via users.tenant_id. Internal staff
 * have tenant_id = NULL and see every tenant; a customer ('kunde') is always
 * bound and must never see or touch another tenant's data. Enforcement lives in
 * tw_require_tenant() / tw_tenant_filter() below — endpoints call those instead
 * of re-implementing the check, so there is exactly one place this can be wrong.
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

/** Roles allowed to author content (presentations/slides/widgets/media). */
function tw_require_manage(): string
{
    return tw_require_role('admin', 'koordinator', 'kunde');
}

/**
 * Roles allowed to manage infrastructure: tenants, users, devices, uploads of
 * record. Customers author content but never provision — so 'kunde' is out.
 */
function tw_require_staff(): string
{
    return tw_require_role('admin', 'koordinator');
}

/** Back-compat admin-only guard. */
function tw_require_admin(): void
{
    tw_require_role('admin');
}

// ---------------------------------------------------------------------------
// Tenant scoping
// ---------------------------------------------------------------------------

/**
 * The tenant this actor is confined to, or null when they may see all tenants.
 *
 * Read from the DB (not the session) so that rebinding or deactivating a user
 * takes effect on their very next request instead of whenever they happen to
 * log in again. Cached per request.
 */
function tw_current_tenant_id(): ?int
{
    static $resolved = false;
    static $tenantId = null;

    if ($resolved) {
        return $tenantId;
    }
    $resolved = true;

    $uid = tw_current_user_id();
    if ($uid === null) {
        return $tenantId = null; // X-Admin-Token / API access is global
    }
    try {
        $st = tw_db()->prepare('SELECT tenant_id FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $v = $st->fetchColumn();
    } catch (Throwable $e) {
        $v = null; // column missing before migration -> behave as before
    }
    return $tenantId = ($v === null || $v === false) ? null : (int) $v;
}

/** True when the actor may only ever touch a single tenant. */
function tw_is_tenant_bound(): bool
{
    return tw_current_tenant_id() !== null;
}

/**
 * Assert the actor may act on $tenantId. A tenant-bound actor reaching for any
 * other tenant is a 403 — this is the check that keeps one customer out of
 * another customer's data, so it must be called on every tenant-owned row an
 * endpoint reads or writes.
 */
function tw_require_tenant(?int $tenantId): void
{
    $own = tw_current_tenant_id();
    if ($own === null) {
        return; // internal staff
    }
    if ($tenantId === null || $tenantId !== $own) {
        tw_json(['error' => 'forbidden'], 403);
    }
}

/**
 * SQL fragment + bindings that narrow a query to the actor's tenant.
 * Returns ['', []] for internal staff (no narrowing).
 *
 *   [$sql, $args] = tw_tenant_filter('p.tenant_id');
 *   $st = $pdo->prepare("SELECT * FROM presentations p WHERE 1=1 $sql");
 *   $st->execute($args);
 */
function tw_tenant_filter(string $column): array
{
    $own = tw_current_tenant_id();
    return $own === null ? ['', []] : [" AND $column = ?", [$own]];
}

/**
 * The tenant a newly created row must belong to. A tenant-bound actor may not
 * choose — anything they create lands in their own tenant, whatever the request
 * body claims. Staff get the requested value.
 */
function tw_owning_tenant(mixed $requested): ?int
{
    $own = tw_current_tenant_id();
    if ($own !== null) {
        return $own;
    }
    $v = (int) $requested;
    return $v > 0 ? $v : null;
}
