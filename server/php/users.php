<?php
/**
 * User CRUD for the dashboard (admin + koordinator).
 *   GET                         -> { users:[{id,email,role,salutation,first_name,last_name,initials,note,active}] }
 *   POST  {email, role, temp_password, salutation?, first_name?, last_name?, initials?, note?, active?}
 *   PUT   {id, role?, salutation?, first_name?, last_name?, initials?, note?, active?}   (email is NOT changeable)
 *   PUT   {id, action:'reset_password', temp_password}
 *   DELETE ?id=
 *
 * Role rules: a koordinator may never create, edit, delete or reset an ADMIN, and
 * may never assign the 'admin' role. The last remaining admin cannot be deleted,
 * demoted or deactivated.
 */
require __DIR__ . '/auth.php';
$actorRole = tw_require_staff();           // 'admin' or 'koordinator' — never 'kunde'
$actorId   = tw_current_user_id();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];
const ROLES = ['admin', 'koordinator', 'betrachter', 'kunde'];

/**
 * A 'kunde' is meaningless without a tenant — login.php refuses such an account
 * outright — so reject the combination at the point it would be created.
 */
function tw_check_kunde_tenant(PDO $pdo, string $role, ?int $tenantId): ?int
{
    if ($role !== 'kunde') {
        return $tenantId;
    }
    if ($tenantId === null || $tenantId <= 0) {
        tw_json(['error' => 'kunde_requires_tenant'], 422);
    }
    $chk = $pdo->prepare('SELECT id FROM tenants WHERE id = ?');
    $chk->execute([$tenantId]);
    if (!$chk->fetch()) {
        tw_json(['error' => 'tenant_not_found'], 422);
    }
    return $tenantId;
}

function tw_public_user(array $u): array
{
    unset($u['pass_hash'], $u['must_change_pw']);
    $u['id']        = (int) $u['id'];
    $u['active']    = (int) $u['active'];
    $u['tenant_id'] = isset($u['tenant_id']) && $u['tenant_id'] !== null ? (int) $u['tenant_id'] : null;
    return $u;
}
function tw_find_user(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}
function tw_active_admin_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
}

if ($method === 'GET') {
    $rows = $pdo->query(
        'SELECT id, email, role, tenant_id, salutation, first_name, last_name, initials, note, active, must_change_pw
           FROM users ORDER BY role, last_name, first_name, id'
    )->fetchAll();
    tw_json(['users' => array_map(fn ($u) => tw_public_user($u), $rows)]);
}

if ($method === 'POST') {
    $b = tw_body();
    $email = trim((string) ($b['email'] ?? ''));
    $role  = (string) ($b['role'] ?? 'betrachter');
    $temp  = (string) ($b['temp_password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        tw_json(['error' => 'invalid_email'], 422);
    }
    if (!in_array($role, ROLES, true)) {
        tw_json(['error' => 'invalid_role'], 422);
    }
    if (strlen($temp) < 8) {
        tw_json(['error' => 'temp_password_too_short'], 422);
    }
    if ($actorRole !== 'admin' && $role === 'admin') {
        tw_json(['error' => 'forbidden_assign_admin'], 403);
    }
    $tenantId = isset($b['tenant_id']) && $b['tenant_id'] !== '' && $b['tenant_id'] !== null
        ? (int) $b['tenant_id'] : null;
    $tenantId = tw_check_kunde_tenant($pdo, $role, $tenantId);
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        tw_json(['error' => 'email_taken'], 409);
    }
    $st = $pdo->prepare(
        'INSERT INTO users (email, pass_hash, role, tenant_id, salutation, first_name, last_name, initials, note, active, must_change_pw)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $st->execute([
        $email,
        password_hash($temp, PASSWORD_DEFAULT),
        $role,
        $tenantId,
        trim((string) ($b['salutation'] ?? '')),
        trim((string) ($b['first_name'] ?? '')),
        trim((string) ($b['last_name'] ?? '')),
        trim((string) ($b['initials'] ?? '')),
        (string) ($b['note'] ?? ''),
        isset($b['active']) ? (int) (bool) $b['active'] : 1,
    ]);
    tw_json(['id' => (int) $pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    $b = tw_body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $target = tw_find_user($pdo, $id);
    if (!$target) {
        tw_json(['error' => 'not_found'], 404);
    }
    // A koordinator may not touch an admin account.
    if ($actorRole !== 'admin' && $target['role'] === 'admin') {
        tw_json(['error' => 'forbidden'], 403);
    }

    // Password reset -> new temp password, forced change on next login.
    if (($b['action'] ?? '') === 'reset_password') {
        $temp = (string) ($b['temp_password'] ?? '');
        if (strlen($temp) < 8) {
            tw_json(['error' => 'temp_password_too_short'], 422);
        }
        $pdo->prepare('UPDATE users SET pass_hash = ?, must_change_pw = 1 WHERE id = ?')
            ->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
        tw_json(['ok' => true]);
    }

    // Field update (email is immutable).
    $role = array_key_exists('role', $b) ? (string) $b['role'] : $target['role'];
    if (!in_array($role, ROLES, true)) {
        tw_json(['error' => 'invalid_role'], 422);
    }
    if ($actorRole !== 'admin' && $role === 'admin') {
        tw_json(['error' => 'forbidden_assign_admin'], 403);
    }
    $active = array_key_exists('active', $b) ? (int) (bool) $b['active'] : (int) $target['active'];

    // Protect the last active admin from being demoted or deactivated.
    if ($target['role'] === 'admin' && (int) $target['active'] === 1
        && ($role !== 'admin' || $active !== 1) && tw_active_admin_count($pdo) <= 1) {
        tw_json(['error' => 'last_admin'], 409);
    }

    $tenantId = array_key_exists('tenant_id', $b)
        ? (($b['tenant_id'] === '' || $b['tenant_id'] === null) ? null : (int) $b['tenant_id'])
        : (isset($target['tenant_id']) && $target['tenant_id'] !== null ? (int) $target['tenant_id'] : null);
    // Promoting someone to 'kunde' without a tenant would lock them out at login.
    $tenantId = tw_check_kunde_tenant($pdo, $role, $tenantId);
    // Only a customer is confined to a tenant; any other role sees everything.
    if ($role !== 'kunde') {
        $tenantId = null;
    }

    $st = $pdo->prepare(
        'UPDATE users SET role = ?, tenant_id = ?, salutation = ?, first_name = ?, last_name = ?, initials = ?, note = ?, active = ?
           WHERE id = ?'
    );
    $st->execute([
        $role,
        $tenantId,
        trim((string) ($b['salutation'] ?? $target['salutation'])),
        trim((string) ($b['first_name'] ?? $target['first_name'])),
        trim((string) ($b['last_name'] ?? $target['last_name'])),
        trim((string) ($b['initials'] ?? $target['initials'])),
        (string) ($b['note'] ?? $target['note']),
        $active,
        $id,
    ]);
    tw_json(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? (tw_body()['id'] ?? 0));
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $target = tw_find_user($pdo, $id);
    if (!$target) {
        tw_json(['error' => 'not_found'], 404);
    }
    if ($actorRole !== 'admin' && $target['role'] === 'admin') {
        tw_json(['error' => 'forbidden'], 403);
    }
    if ($actorId !== null && $id === $actorId) {
        tw_json(['error' => 'cannot_delete_self'], 409);
    }
    if ($target['role'] === 'admin' && (int) $target['active'] === 1 && tw_active_admin_count($pdo) <= 1) {
        tw_json(['error' => 'last_admin'], 409);
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    tw_json(['deleted' => $id]);
}

tw_json(['error' => 'method_not_allowed'], 405);
