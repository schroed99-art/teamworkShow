<?php
/**
 * Device online/offline monitor. Runs from cron every minute:
 *   * * * * * php /var/www/html/teamworkshow/device_monitor.php >/dev/null 2>&1
 *
 * A device pulls playlist.php every ~60s (stamps devices.last_seen). This script
 * classifies each device (online/offline/alarm/never via status_util.php),
 * compares it against the last known state in `device_status`, and on any change
 * appends a line to logs/device_status.log. When a device stays offline past the
 * alarm window it logs an ALARM once and (later) notifies the admin by e-mail.
 *
 * Triggered either from a real CLI cron (SSH) or — on shared hosting whose cron
 * only calls URLs (All-Inkl KAS) — via HTTPS with a secret token:
 *   https://…/device_monitor.php?key=<cron_key>
 * The CLI path always runs; the web path requires ?key to match cron_key.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/status_util.php';
require __DIR__ . '/mailer.php';

if (PHP_SAPI !== 'cli') {
    $want = (string) (tw_config()['cron_key'] ?? '');
    $got  = (string) ($_GET['key'] ?? '');
    if ($want === '' || !hash_equals($want, $got)) {
        http_response_code(403);
        exit("forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
}

$pdo = tw_db();

// --- log file (kept out of the web via logs/.htaccess) ----------------------
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$htaccess = $logDir . '/.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess, "Require all denied\n");
}
$logFile = $logDir . '/device_status.log';

function tw_log(string $logFile, string $msg): void
{
    @file_put_contents($logFile, date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND);
}

/**
 * Recipients for device-offline alarms: the configured ALARM_TO list, or — when
 * empty — every active admin's login e-mail. De-duplicated, validated.
 */
function tw_alarm_recipients(): array
{
    $configured = tw_mail_config()['alarm_to'];
    $list = $configured !== ''
        ? preg_split('/[,;]+/', $configured)
        : [];
    if (!$list) {
        try {
            $rows = tw_db()->query("SELECT email FROM users WHERE role = 'admin' AND active = 1")->fetchAll();
            $list = array_map(static fn($r) => (string) $r['email'], $rows);
        } catch (Throwable $e) {
            $list = [];
        }
    }
    $clean = [];
    foreach ($list as $e) {
        $e = trim((string) $e);
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $clean[strtolower($e)] = $e;
        }
    }
    return array_values($clean);
}

/**
 * Notify the admin(s) that a device has gone into alarm (offline past the window).
 * Sends one e-mail via SMTP; when mail is disabled/unconfigured or fails it simply
 * logs the reason and returns — the monitor must never die on a mail hiccup.
 */
function tw_notify_admin_alarm(string $logFile, array $dev, ?int $secs): void
{
    $code = (string) ($dev['pairing_code'] ?? '');
    $name = ($dev['name'] ?? '') !== '' ? (string) $dev['name'] : '(ohne Name)';
    $ago  = tw_ago_human($secs);

    if (!tw_mail_enabled()) {
        tw_log($logFile, sprintf('EMAIL (nicht konfiguriert) -> Admin: Gerät %s "%s" seit %s offline', $code, $name, $ago));
        return;
    }
    $to = tw_alarm_recipients();
    if (!$to) {
        tw_log($logFile, sprintf('EMAIL (kein Empfänger) -> Gerät %s "%s" seit %s offline', $code, $name, $ago));
        return;
    }

    $subject = sprintf('⚠ TeamworkShow: Gerät "%s" offline', $name);
    $eName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $eCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $eAgo  = htmlspecialchars($ago, ENT_QUOTES, 'UTF-8');
    $html = "<div style=\"font-family:system-ui,Arial,sans-serif;color:#0F172A\">"
        . "<h2 style=\"color:#D21A55;margin:0 0 8px\">Gerät offline</h2>"
        . "<p>Ein Anzeigegerät meldet sich seit <b>$eAgo</b> nicht mehr.</p>"
        . "<table style=\"border-collapse:collapse;font-size:14px\">"
        . "<tr><td style=\"padding:2px 12px 2px 0;color:#64748B\">Gerät</td><td><b>$eName</b></td></tr>"
        . "<tr><td style=\"padding:2px 12px 2px 0;color:#64748B\">Pairing-Code</td><td>$eCode</td></tr>"
        . "<tr><td style=\"padding:2px 12px 2px 0;color:#64748B\">Offline seit</td><td>$eAgo</td></tr>"
        . "</table>"
        . "<p style=\"color:#64748B;font-size:12px;margin-top:16px\">Automatische Meldung des TeamworkShow-Gerätemonitors.</p>"
        . "</div>";

    $res = tw_send_mail($to, $subject, $html);
    if ($res['ok']) {
        tw_log($logFile, sprintf('EMAIL gesendet -> %s: Gerät %s "%s" seit %s offline', implode(', ', $res['sent']), $code, $name, $ago));
    } else {
        tw_log($logFile, sprintf('EMAIL FEHLER (%s) -> Gerät %s "%s" seit %s offline', (string) $res['error'], $code, $name, $ago));
    }
}

// Ensure the state table exists (idempotent — also created by the migration).
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS device_status (
        device_id INT UNSIGNED NOT NULL PRIMARY KEY,
        status    VARCHAR(16) NOT NULL DEFAULT \'never\',
        since     DATETIME NOT NULL,
        alerted   TINYINT(1) NOT NULL DEFAULT 0,
        CONSTRAINT fk_device_status_device FOREIGN KEY (device_id)
            REFERENCES devices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$devices = $pdo->query(
    'SELECT id, pairing_code, name, tenant_id,
            TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS secs
       FROM devices'
)->fetchAll(PDO::FETCH_ASSOC);

$prev = [];
foreach ($pdo->query('SELECT device_id, status, alerted FROM device_status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $prev[(int) $r['device_id']] = ['status' => $r['status'], 'alerted' => (int) $r['alerted']];
}

$ins = $pdo->prepare(
    'INSERT INTO device_status (device_id, status, since, alerted)
        VALUES (?, ?, NOW(), ?)
     ON DUPLICATE KEY UPDATE status = VALUES(status), since = VALUES(since), alerted = VALUES(alerted)'
);
$setAlerted = $pdo->prepare('UPDATE device_status SET alerted = 1 WHERE device_id = ?');

foreach ($devices as $d) {
    $id = (int) $d['id'];
    $secs = $d['secs'] === null ? null : (int) $d['secs'];
    $status = tw_device_status($secs);
    $old = $prev[$id] ?? null;

    if ($old === null) {
        // First time we see this device. Online/never is the normal baseline and
        // stays silent; an already-offline/alarm device is worth a first log line.
        $ins->execute([$id, $status, $status === 'alarm' ? 1 : 0]);
        if ($status === 'offline' || $status === 'alarm') {
            tw_log(
                $logFile,
                sprintf('%-7s %s "%s"  (erstmals erfasst, %s)', strtoupper($status), $d['pairing_code'], $d['name'], tw_ago_human($secs))
            );
            if ($status === 'alarm') {
                tw_notify_admin_alarm($logFile, $d, $secs);
            }
        }
        continue;
    }

    if ($status !== $old['status']) {
        // Transition: log it and reset the alarm flag for the new state.
        tw_log(
            $logFile,
            sprintf(
                '%-7s %s "%s"  (%s -> %s%s)',
                strtoupper($status),
                $d['pairing_code'],
                $d['name'],
                $old['status'],
                $status,
                $status === 'online' ? '' : ', ' . tw_ago_human($secs)
            )
        );
        $alerted = $status === 'alarm' ? 1 : 0;
        $ins->execute([$id, $status, $alerted]);
        if ($status === 'alarm') {
            tw_notify_admin_alarm($logFile, $d, $secs);
        }
    } elseif ($status === 'alarm' && $old['alerted'] === 0) {
        // Still alarm but not yet alerted (e.g. crossed the threshold without a
        // status label change) — alert once.
        tw_log($logFile, sprintf('ALARM  %s "%s"  offline (%s)', $d['pairing_code'], $d['name'], tw_ago_human($secs)));
        tw_notify_admin_alarm($logFile, $d, $secs);
        $setAlerted->execute([$id]);
    }
}

echo "checked " . count($devices) . " device(s)\n";
