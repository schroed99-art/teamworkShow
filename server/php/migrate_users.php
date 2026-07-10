<?php
/**
 * Idempotent migration: creates the `users` table and, when it is empty,
 * bootstraps the first admin from the current config admin_password.
 *
 * CLI only (not web-reachable). Run once on the VM after deploy:
 *   php /var/www/html/teamworkshow/migrate_users.php
 *
 * The bootstrap admin logs in with email schroed99@googlemail.com and the
 * existing config admin_password as a TEMP password; must_change_pw forces a
 * self-chosen password on first login.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';
$pdo = tw_db();

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email          VARCHAR(190) NOT NULL,
    pass_hash      VARCHAR(255) NOT NULL,
    role           ENUM('admin','koordinator','betrachter') NOT NULL DEFAULT 'betrachter',
    salutation     VARCHAR(20)  NOT NULL DEFAULT '',
    first_name     VARCHAR(80)  NOT NULL DEFAULT '',
    last_name      VARCHAR(80)  NOT NULL DEFAULT '',
    initials       VARCHAR(12)  NOT NULL DEFAULT '',
    note           TEXT NULL,
    active         TINYINT(1) NOT NULL DEFAULT 1,
    must_change_pw TINYINT(1) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "users table ready\n";

$count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($count > 0) {
    echo "users present ($count) — no bootstrap needed\n";
    exit(0);
}

$pass = (string) (tw_config()['admin_password'] ?? '');
if ($pass === '' || $pass === 'CHANGE_ME') {
    exit("ERROR: admin_password not set in config.php — cannot bootstrap first admin\n");
}
$hash = password_hash($pass, PASSWORD_DEFAULT);
$st = $pdo->prepare(
    "INSERT INTO users (email, pass_hash, role, salutation, first_name, last_name, initials, active, must_change_pw)
     VALUES (?, ?, 'admin', '', 'Admin', '', 'AD', 1, 1)"
);
$st->execute(['schroed99@googlemail.com', $hash]);
echo "bootstrapped first admin: schroed99@googlemail.com\n";
echo "  temp password = current config admin_password; will be forced to change on first login\n";
