<?php
/**
 * Idempotent migration (Phase 5.2, Mandanten-Self-Service): bind a user to a
 * tenant and introduce the customer role.
 *
 *   users.tenant_id  NULL  -> internal staff, sees every tenant (today's behaviour)
 *                    set   -> the user is confined to that one tenant
 *   users.role             -> gains 'kunde' (customer self-service)
 *
 * Existing users keep tenant_id = NULL, so nothing changes for the current
 * admins until a user is deliberately bound to a tenant.
 *
 * CLI only. Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_user_tenant.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

// ---- users.tenant_id -------------------------------------------------------
$hasCol = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'tenant_id'"
)->fetchColumn();

if ($hasCol === 0) {
    $pdo->exec(
        "ALTER TABLE users
           ADD COLUMN tenant_id INT UNSIGNED NULL DEFAULT NULL AFTER role"
    );
    echo "added users.tenant_id\n";
} else {
    echo "users.tenant_id already present\n";
}

// ---- FK users.tenant_id -> tenants.id --------------------------------------
// ON DELETE CASCADE: deleting a tenant removes its customer logins with it, so
// no login can ever outlive the tenant it was scoped to.
$hasFk = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND CONSTRAINT_NAME = 'fk_users_tenant'
        AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
)->fetchColumn();

if ($hasFk === 0) {
    $pdo->exec(
        "ALTER TABLE users
           ADD CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id)
           REFERENCES tenants(id) ON DELETE CASCADE"
    );
    echo "added FK users.tenant_id -> tenants.id\n";
} else {
    echo "FK fk_users_tenant already present\n";
}

// ---- users.role gains 'kunde' ----------------------------------------------
$roleType = (string) $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'role'"
)->fetchColumn();

if (stripos($roleType, "'kunde'") === false) {
    $pdo->exec(
        "ALTER TABLE users
           MODIFY COLUMN role ENUM('admin','koordinator','betrachter','kunde')
           NOT NULL DEFAULT 'betrachter'"
    );
    echo "added role 'kunde'\n";
} else {
    echo "role 'kunde' already present\n";
}

// ---- guard: a 'kunde' without a tenant would see everything -----------------
// Report, don't auto-fix: silently rebinding someone's login is not this
// script's call.
$orphans = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'kunde' AND tenant_id IS NULL"
)->fetchColumn();
if ($orphans > 0) {
    echo "WARNING: $orphans user(s) with role 'kunde' have no tenant_id — "
       . "they are rejected at login until a tenant is assigned.\n";
}

echo "done\n";
