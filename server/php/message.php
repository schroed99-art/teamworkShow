<?php
/**
 * Send a free-text message by e-mail to a customer (Teamwork -> Kunde).
 *
 *   POST {subject, body, user_id?}    -> mail one specific user
 *   POST {subject, body, tenant_id?}  -> mail every active login of that tenant
 *
 * Staff-only (admin/koordinator): this is a tool for us to reach customers, not
 * something a customer may use. Recipients are ALWAYS resolved from our own users
 * table (by user_id or tenant_id) — never a free-form address in the body — so the
 * endpoint can only ever mail accounts we already have, not act as an open relay.
 *
 * Reply-To is set to the sending staffer's own login e-mail when available, so a
 * customer's reply reaches a real person.
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/mailer.php';
tw_require_staff();

$pdo = tw_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tw_json(['error' => 'method_not_allowed'], 405);
}
if (!tw_mail_enabled()) {
    tw_json(['ok' => false, 'error' => 'mail_disabled'], 503);
}

$b = tw_body();
$subject = trim((string) ($b['subject'] ?? ''));
$body    = trim((string) ($b['body'] ?? ''));
if ($subject === '' || $body === '') {
    tw_json(['error' => 'subject_and_body_required'], 422);
}

$userId   = (int) ($b['user_id'] ?? 0);
$tenantId = (int) ($b['tenant_id'] ?? 0);

$recipients = [];
if ($userId > 0) {
    $s = $pdo->prepare('SELECT email FROM users WHERE id = ? AND active = 1');
    $s->execute([$userId]);
    if ($e = $s->fetchColumn()) {
        $recipients[] = (string) $e;
    }
} elseif ($tenantId > 0) {
    $s = $pdo->prepare("SELECT email FROM users WHERE tenant_id = ? AND active = 1 ORDER BY id");
    $s->execute([$tenantId]);
    $recipients = array_map(static fn($r) => (string) $r['email'], $s->fetchAll());
} else {
    tw_json(['error' => 'user_id_or_tenant_id_required'], 422);
}

$recipients = array_values(array_filter(
    $recipients,
    static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
));
if (!$recipients) {
    tw_json(['error' => 'no_recipient'], 404);
}

// Reply-To = the acting staffer's own e-mail, so replies reach a person.
$replyTo = null;
$uid = tw_current_user_id();
if ($uid !== null) {
    $s = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $s->execute([$uid]);
    $me = (string) ($s->fetchColumn() ?: '');
    if (filter_var($me, FILTER_VALIDATE_EMAIL)) {
        $replyTo = $me;
    }
}

$eBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
$html = "<div style=\"font-family:system-ui,Arial,sans-serif;color:#0F172A;font-size:15px;line-height:1.5\">"
    . "<div style=\"height:4px;background:#D21A55;border-radius:2px;width:48px;margin-bottom:14px\"></div>"
    . "<div>$eBody</div>"
    . "<p style=\"color:#94A3B8;font-size:12px;margin-top:20px\">Gesendet über TeamworkShow</p>"
    . "</div>";

// One mail per recipient so addresses are not disclosed to each other.
$sent = [];
$errors = [];
foreach ($recipients as $rcpt) {
    $res = tw_send_mail($rcpt, $subject, $html, $body, $replyTo);
    if ($res['ok']) {
        $sent[] = $rcpt;
    } else {
        $errors[] = ['to' => $rcpt, 'error' => $res['error']];
    }
}

tw_json(['ok' => count($errors) === 0, 'sent' => $sent, 'errors' => $errors]);
