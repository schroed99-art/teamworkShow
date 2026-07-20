<?php
/**
 * Lightweight audit trail: who signed in, when a device connected / went
 * unreachable, when it synced or self-updated, plus admin account actions.
 *
 * Functions-only include (no side effects) — safe to require from any endpoint
 * or the device-monitor cron. Every write is best-effort: a logging hiccup must
 * never break the request it observes, so tw_audit() swallows all errors.
 *
 * PRIVACY: customer data is anonymised at write time. A 'kunde' login e-mail is
 * masked (tw_mask_email), tenants are referenced by numeric id only, and device
 * names (which may carry a customer name) are never stored — only the opaque
 * pairing code. Staff e-mails are kept in clear so the trail stays useful for us.
 */
require_once __DIR__ . '/db.php';

/** Mask an e-mail for the log: keep 2 leading local chars + the TLD, hide the rest. */
function tw_mask_email(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '(unbekannt)';
    }
    $at = strpos($s, '@');
    if ($at === false) {
        return tw_mask_token($s);
    }
    $local = substr($s, 0, $at);
    $domain = substr($s, $at + 1);
    $dot = strrpos($domain, '.');
    $tld = $dot !== false ? substr($domain, $dot) : '';
    return substr($local, 0, 2) . '***@***' . $tld;
}

/** Mask the middle of an arbitrary token, keeping the first two characters. */
function tw_mask_token(string $s): string
{
    $n = strlen($s);
    if ($n <= 2) {
        return '***';
    }
    return substr($s, 0, 2) . str_repeat('*', min(3, $n - 2));
}

/** Create the audit table once per request (idempotent, cheap after the first call). */
function tw_audit_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ts DATETIME NOT NULL,
            category VARCHAR(16) NOT NULL,
            event VARCHAR(40) NOT NULL,
            actor VARCHAR(160) NULL,
            device_code VARCHAR(32) NULL,
            tenant_id INT UNSIGNED NULL,
            detail VARCHAR(255) NULL,
            ip VARCHAR(45) NULL,
            KEY idx_ts (ts),
            KEY idx_cat (category),
            KEY idx_dev (device_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** Compact human duration for log details, e.g. "8 h", "12 min", "3 Tage". */
function tw_dur_human(?int $secs): string
{
    $s = max(0, (int) $secs);
    if ($s < 60) {
        return $s . ' s';
    }
    $m = intdiv($s, 60);
    if ($m < 60) {
        return $m . ' min';
    }
    $h = intdiv($m, 60);
    if ($h < 48) {
        return $h . ' h';
    }
    return intdiv($h, 24) . ' Tage';
}

/** Best-effort client IP from the current request (empty on CLI). */
function tw_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Append one audit row. $o keys: actor, actor_email, actor_role, device_code,
 * tenant_id, detail, ip. Builds an anonymised actor label from actor_email when
 * no explicit actor is given (a 'kunde' or unknown-role e-mail is masked). Never throws.
 */
function tw_audit(string $category, string $event, array $o = []): void
{
    try {
        $pdo = tw_db();
        tw_audit_ensure_table($pdo);

        $actor = isset($o['actor']) ? (string) $o['actor'] : '';
        if ($actor === '' && !empty($o['actor_email'])) {
            $role  = (string) ($o['actor_role'] ?? '');
            $email = (string) $o['actor_email'];
            $shown = ($role === 'kunde' || $role === '') ? tw_mask_email($email) : $email;
            $actor = $role !== '' ? ucfirst($role) . ' ' . $shown : $shown;
        }
        $actor = $actor !== '' ? mb_substr($actor, 0, 160) : null;

        $st = $pdo->prepare(
            'INSERT INTO audit_log (ts, category, event, actor, device_code, tenant_id, detail, ip)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            mb_substr($category, 0, 16),
            mb_substr($event, 0, 40),
            $actor,
            isset($o['device_code']) && $o['device_code'] !== '' ? mb_substr((string) $o['device_code'], 0, 32) : null,
            isset($o['tenant_id']) && $o['tenant_id'] !== null && $o['tenant_id'] !== '' ? (int) $o['tenant_id'] : null,
            isset($o['detail']) && $o['detail'] !== '' ? mb_substr((string) $o['detail'], 0, 255) : null,
            array_key_exists('ip', $o) ? mb_substr((string) $o['ip'], 0, 45) : (tw_client_ip() ?: null),
        ]);
    } catch (Throwable $e) {
        // Never let logging break the observed request.
    }
}

/**
 * Like tw_audit(), but only writes when no row with the same event+device_code
 * exists within the last $minGapSeconds — keeps the high-frequency device sync
 * heartbeat from flooding the log. Never throws. $minGapSeconds is cast to int
 * and inlined (real prepared statements reject a placeholder inside INTERVAL).
 */
function tw_audit_throttled(string $category, string $event, string $deviceCode, int $minGapSeconds, array $o = []): void
{
    try {
        $pdo = tw_db();
        tw_audit_ensure_table($pdo);
        $st = $pdo->prepare(
            'SELECT 1 FROM audit_log
              WHERE event = ? AND device_code = ? AND ts > (NOW() - INTERVAL ' . (int) $minGapSeconds . ' SECOND)
              LIMIT 1'
        );
        $st->execute([$event, $deviceCode]);
        if ($st->fetchColumn()) {
            return; // logged recently — skip
        }
    } catch (Throwable $e) {
        return; // on any doubt, do not spam
    }
    $o['device_code'] = $deviceCode;
    tw_audit($category, $event, $o);
}

/** Trim rows older than $days so the trail stays "small". Best-effort. */
function tw_audit_prune(int $days = 90): void
{
    try {
        $pdo = tw_db();
        tw_audit_ensure_table($pdo);
        $pdo->exec('DELETE FROM audit_log WHERE ts < (NOW() - INTERVAL ' . (int) $days . ' DAY)');
    } catch (Throwable $e) {
    }
}
