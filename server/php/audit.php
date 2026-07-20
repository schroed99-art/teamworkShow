<?php
/**
 * Audit-trail viewer (admin only). Feeds the Einstellungen -> Protokoll panel.
 *
 *   GET audit.php?category=<auth|device|sync|update|admin>&limit=200
 *       -> { rows: [ { ts, category, event, label, actor, device_code, tenant_id, detail }, ... ] }
 *
 * Rows are already anonymised at write time (see audit_log.php). Newest first.
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/audit_log.php';
tw_require_admin();

/** Machine event slugs -> German labels shown in the table. */
const AUDIT_EVENT_LABELS = [
    'login_ok'            => 'Anmeldung',
    'login_fail'          => 'Anmeldung fehlgeschlagen',
    'logout'              => 'Abmeldung',
    'password_changed'    => 'Passwort geändert',
    'password_reset'      => 'Passwort zurückgesetzt',
    'user_created'        => 'Benutzer angelegt',
    'device_online'       => 'Gerät verbunden',
    'device_offline'      => 'Gerät nicht erreichbar',
    'device_alarm'        => 'Gerät im Alarm (lange offline)',
    'device_sync'         => 'Gerät synchronisiert',
    'app_update_download' => 'App-Update geladen',
];

$pdo = tw_db();
tw_audit_ensure_table($pdo);

$cat = isset($_GET['category']) ? preg_replace('/[^a-z]/', '', strtolower((string) $_GET['category'])) : '';
$limit = (int) ($_GET['limit'] ?? 200);
$limit = max(1, min(1000, $limit));

$where = '';
$args = [];
if ($cat !== '' && in_array($cat, ['auth', 'device', 'sync', 'update', 'admin'], true)) {
    $where = 'WHERE category = ?';
    $args[] = $cat;
}
// $limit is an int; inlined because a placeholder inside LIMIT is rejected by
// real (non-emulated) prepared statements, which this connection uses.
$st = $pdo->prepare(
    "SELECT ts, category, event, actor, device_code, tenant_id, detail
       FROM audit_log $where ORDER BY id DESC LIMIT " . $limit
);
$st->execute($args);

$rows = [];
foreach ($st as $r) {
    $ev = (string) $r['event'];
    $rows[] = [
        'ts'          => (string) $r['ts'],
        'category'    => (string) $r['category'],
        'event'       => $ev,
        'label'       => AUDIT_EVENT_LABELS[$ev] ?? $ev,
        'actor'       => $r['actor'] !== null ? (string) $r['actor'] : '',
        'device_code' => $r['device_code'] !== null ? (string) $r['device_code'] : '',
        'tenant_id'   => $r['tenant_id'] !== null ? (int) $r['tenant_id'] : null,
        'detail'      => $r['detail'] !== null ? (string) $r['detail'] : '',
    ];
}
tw_json(['rows' => $rows]);
