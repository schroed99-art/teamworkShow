<?php
/**
 * Minimal, dependency-free SMTP mailer for the backend.
 *
 * Shared hosting (All-Inkl/KAS) has no Composer, and deploy.sh copies only
 * *.php — so instead of vendoring PHPMailer this is a small hand-rolled SMTP
 * client that speaks exactly what a KAS mailbox needs: implicit TLS on 465
 * (ssl://) or STARTTLS on 587, AUTH LOGIN, UTF-8 subject/body, dot-stuffing.
 *
 * Config comes from tw_config()['smtp'] (env keys SMTP_*, MAIL_FROM*, ALARM_TO
 * — see app.env.sample). If SMTP is not configured the mailer degrades to a
 * no-op that reports {ok:false, error:'mail_disabled'} — callers log and carry
 * on; nothing ever throws fatally (the device-monitor cron must never die on a
 * mail hiccup).
 *
 *   [$ok,] = tw_send_mail('a@b.de', 'Betreff', '<p>HTML</p>', 'Text-Fallback');
 */
require_once __DIR__ . '/db.php';

/** SMTP/mail settings, normalised. Empty host/user/pass => disabled. */
function tw_mail_config(): array
{
    $c = tw_config();
    $s = is_array($c['smtp'] ?? null) ? $c['smtp'] : [];
    $host = trim((string) ($s['host'] ?? ''));
    $user = trim((string) ($s['user'] ?? ''));
    $pass = (string) ($s['pass'] ?? '');
    $from = trim((string) ($s['from'] ?? '')) ?: $user;
    return [
        'host'      => $host,
        'port'      => (int) ($s['port'] ?? 465),
        'security'  => strtolower(trim((string) ($s['security'] ?? 'ssl'))), // ssl|tls|none
        'user'      => $user,
        'pass'      => $pass,
        'from'      => $from,
        'from_name' => trim((string) ($s['from_name'] ?? '')) ?: 'TeamworkShow',
        'alarm_to'  => trim((string) ($s['alarm_to'] ?? '')),
        'enabled'   => $host !== '' && $user !== '' && $pass !== '' && $from !== '',
    ];
}

/** True when SMTP is fully configured and mail can actually be sent. */
function tw_mail_enabled(): bool
{
    return tw_mail_config()['enabled'];
}

/** RFC 2047 UTF-8 header encoding (subject, display name) — only when needed. */
function tw_mail_hdr_encode(string $s): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $s)) {
        return $s; // pure ASCII, no encoding needed
    }
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/** Strip anything that could inject extra headers into an address/subject line. */
function tw_mail_oneline(string $s): string
{
    return trim(str_replace(["\r", "\n", "\0"], '', $s));
}

/** Best-effort dashboard base URL from the current web request (empty on CLI). */
function tw_current_base_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/**
 * Send login credentials (a new account or a password reset) to the user's own
 * login e-mail. The password is a one-time temp password that must be changed on
 * first login (must_change_pw = 1), so it is fine to transport it this way.
 *
 * $tenant, when given, is the recipient's Mandant (keys name/contact_company/
 * contact_address) and is shown as a block so it is obvious from the mail which
 * customer the access belongs to.
 *
 * @param ?array{name?:string,contact_company?:string,contact_address?:string} $tenant
 * @return array{ok:bool, error:?string, sent:string[]}
 */
function tw_mail_credentials(string $to, string $name, string $email, string $tempPassword, bool $isReset, ?array $tenant = null): array
{
    $base = tw_current_base_url();
    // Point at the login page (not the admin app) and pre-fill the e-mail so the
    // recipient only has to type the password.
    $loginUrl = '';
    if ($base !== '') {
        $loginUrl = $base . '/login.php';
        if ($email !== '') {
            $loginUrl .= '?email=' . rawurlencode($email);
        }
    }
    $eName = htmlspecialchars($name !== '' ? $name : $email, ENT_QUOTES, 'UTF-8');
    $eEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $ePw   = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');
    $intro = $isReset
        ? 'Ihr Passwort für den TeamworkShow-Zugang wurde zurückgesetzt.'
        : 'für Sie wurde ein Zugang zum TeamworkShow-Dashboard angelegt.';
    $subject = $isReset ? 'TeamworkShow: Passwort zurückgesetzt' : 'TeamworkShow: Ihr Zugang';

    // Which customer/Mandant does this access belong to? Shown up top so it is
    // recognisable at a glance. Empty fields are skipped; a company identical to
    // the tenant name is not repeated.
    $tenantHtml = '';
    if (is_array($tenant)) {
        $tName = trim((string) ($tenant['name'] ?? ''));
        $tComp = trim((string) ($tenant['contact_company'] ?? ''));
        $tAddr = trim((string) ($tenant['contact_address'] ?? ''));
        $lines = [];
        if ($tName !== '') {
            $lines[] = '<b>' . htmlspecialchars($tName, ENT_QUOTES, 'UTF-8') . '</b>';
        }
        if ($tComp !== '' && $tComp !== $tName) {
            $lines[] = htmlspecialchars($tComp, ENT_QUOTES, 'UTF-8');
        }
        if ($tAddr !== '') {
            $lines[] = nl2br(htmlspecialchars($tAddr, ENT_QUOTES, 'UTF-8'));
        }
        if ($lines) {
            $tenantHtml = '<p style="color:#64748B;font-size:13px;margin:12px 0 4px">Kunde / Mandant</p>'
                . '<div style="border-left:3px solid #D21A55;padding:2px 0 2px 10px;font-size:14px;line-height:1.5">'
                . implode('<br>', $lines)
                . '</div>';
        }
    }

    $linkHtml = $loginUrl !== ''
        ? '<p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#D21A55">Zum Login</a></p>'
        : '';
    $html = "<div style=\"font-family:system-ui,Arial,sans-serif;color:#0F172A\">"
        . "<h2 style=\"color:#0F172A;margin:0 0 8px\">Teamwork<span style=\"color:#D21A55\">Show</span></h2>"
        . "<p>Hallo $eName,<br>$intro</p>"
        . $tenantHtml
        . "<table style=\"border-collapse:collapse;font-size:14px;margin:8px 0\">"
        . "<tr><td style=\"padding:2px 12px 2px 0;color:#64748B\">Login (E-Mail)</td><td><b>$eEmail</b></td></tr>"
        . "<tr><td style=\"padding:2px 12px 2px 0;color:#64748B\">Passwort</td><td><b>$ePw</b></td></tr>"
        . "</table>"
        . "<p style=\"color:#64748B;font-size:13px\">Bitte ändern Sie das Passwort bei der ersten Anmeldung.</p>"
        . $linkHtml
        . "</div>";

    return tw_send_mail($to, $subject, $html);
}

/**
 * Send one mail to one or more recipients.
 *
 * @param string|string[] $to        recipient address(es)
 * @param string          $subject   plain subject (UTF-8)
 * @param string          $htmlBody  HTML body (UTF-8)
 * @param ?string         $textBody  optional plain-text alternative (auto-derived if null)
 * @param ?string         $replyTo   optional Reply-To address
 * @return array{ok:bool, error:?string, sent:string[]}
 */
function tw_send_mail($to, string $subject, string $htmlBody, ?string $textBody = null, ?string $replyTo = null): array
{
    $cfg = tw_mail_config();
    $recipients = array_values(array_filter(array_map(
        static fn($a) => tw_mail_oneline((string) $a),
        is_array($to) ? $to : [$to]
    ), static fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));

    if (!$recipients) {
        return ['ok' => false, 'error' => 'no_valid_recipient', 'sent' => []];
    }
    if (!$cfg['enabled']) {
        return ['ok' => false, 'error' => 'mail_disabled', 'sent' => []];
    }

    $subject = tw_mail_oneline($subject);
    if ($textBody === null) {
        // Derive a readable plain-text part from the HTML.
        $textBody = trim(html_entity_decode(
            strip_tags(preg_replace('/<(br|\/p|\/div|\/h[1-6])\s*\/?>/i', "\n", $htmlBody)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
    }

    $boundary = 'tw_' . bin2hex(random_bytes(8));
    $fromHdr = tw_mail_hdr_encode($cfg['from_name']) . ' <' . $cfg['from'] . '>';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . $fromHdr,
        'To: ' . implode(', ', $recipients),
        'Subject: ' . tw_mail_hdr_encode($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: TeamworkShow',
    ];
    if ($replyTo !== null && filter_var($rt = tw_mail_oneline($replyTo), FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $rt;
    }

    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($textBody)) . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($htmlBody)) . "\r\n"
        . "--$boundary--\r\n";

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    try {
        return tw_smtp_deliver($cfg, $recipients, $message);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'smtp_exception: ' . $e->getMessage(), 'sent' => []];
    }
}

/**
 * Talk raw SMTP: connect, EHLO, (STARTTLS), AUTH LOGIN, MAIL FROM, RCPT, DATA.
 * Returns {ok, error, sent}. Never throws past tw_send_mail's catch.
 */
function tw_smtp_deliver(array $cfg, array $recipients, string $message): array
{
    $transport = $cfg['security'] === 'ssl' ? 'ssl://' : '';
    $ctx = stream_context_create(['ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'SNI_enabled'       => true,
    ]]);
    $fp = @stream_socket_client(
        $transport . $cfg['host'] . ':' . $cfg['port'],
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if (!$fp) {
        return ['ok' => false, 'error' => "connect_failed: $errno $errstr", 'sent' => []];
    }
    stream_set_timeout($fp, 15);

    $read = static function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // Multiline replies keep a '-' after the 3-digit code; stop at ' '.
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return [(int) substr($data, 0, 3), $data];
    };
    $write = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $fail = static function (string $where, string $resp) use ($fp): array {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return ['ok' => false, 'error' => "$where: " . trim($resp), 'sent' => []];
    };

    [$code, $resp] = $read();
    if ($code !== 220) {
        return $fail('greeting', $resp);
    }

    $ehloHost = $cfg['host'] ?: 'localhost';
    $write('EHLO ' . $ehloHost);
    [$code, $resp] = $read();
    if ($code !== 250) {
        return $fail('ehlo', $resp);
    }

    if ($cfg['security'] === 'tls') {
        $write('STARTTLS');
        [$code, $resp] = $read();
        if ($code !== 220) {
            return $fail('starttls', $resp);
        }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            @fclose($fp);
            return ['ok' => false, 'error' => 'tls_handshake_failed', 'sent' => []];
        }
        $write('EHLO ' . $ehloHost); // re-EHLO after upgrading the connection
        [$code, $resp] = $read();
        if ($code !== 250) {
            return $fail('ehlo_tls', $resp);
        }
    }

    $write('AUTH LOGIN');
    [$code, $resp] = $read();
    if ($code !== 334) {
        return $fail('auth', $resp);
    }
    $write(base64_encode($cfg['user']));
    [$code, $resp] = $read();
    if ($code !== 334) {
        return $fail('auth_user', $resp);
    }
    $write(base64_encode($cfg['pass']));
    [$code, $resp] = $read();
    if ($code !== 235) {
        return $fail('auth_pass', $resp);
    }

    $write('MAIL FROM:<' . $cfg['from'] . '>');
    [$code, $resp] = $read();
    if ($code !== 250) {
        return $fail('mail_from', $resp);
    }

    $accepted = [];
    foreach ($recipients as $rcpt) {
        $write('RCPT TO:<' . $rcpt . '>');
        [$code, $resp] = $read();
        if ($code === 250 || $code === 251) {
            $accepted[] = $rcpt;
        }
    }
    if (!$accepted) {
        return $fail('rcpt_all_rejected', $resp);
    }

    $write('DATA');
    [$code, $resp] = $read();
    if ($code !== 354) {
        return $fail('data', $resp);
    }
    // Dot-stuffing: any line starting with '.' must be doubled.
    $dotted = preg_replace('/^\./m', '..', $message);
    fwrite($fp, $dotted . "\r\n.\r\n");
    [$code, $resp] = $read();
    if ($code !== 250) {
        return $fail('data_end', $resp);
    }

    $write('QUIT');
    @fclose($fp);
    return ['ok' => true, 'error' => null, 'sent' => $accepted];
}
