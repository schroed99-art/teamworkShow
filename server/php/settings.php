<?php
/**
 * Global app settings (help/contact card). Session/token guarded.
 *   GET   -> { help_company, help_phone, help_email, help_hours, help_text }
 *   POST  (JSON or form) with any of those keys -> saves, then returns the values.
 *
 * The public playlist endpoint reads the same table to deliver the help card to
 * devices; this endpoint is the dashboard's editor for it.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$keys = [
    'help_company',       // Firmenname
    'help_app',           // Application (Produktname)
    'help_version',       // Version (Info-Text; App zeigt sonst ihre echte Version)
    'help_phone',         // Telefon
    'help_contact',       // Ansprechpartner
    'help_support_mail',  // Support: Mail
    'help_support_phone', // Support: Telefon
    'help_website',       // Internetseite
];
$pdo = tw_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tw_require_manage(); // admin/koordinator only
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $ins = $pdo->prepare(
        'INSERT INTO app_settings (k, v) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    foreach ($keys as $k) {
        if (array_key_exists($k, $body)) {
            $ins->execute([$k, (string) $body[$k]]);
        }
    }
} else {
    tw_require_role(); // any authenticated actor may read
}

$out = array_fill_keys($keys, '');
$placeholders = implode(',', array_fill(0, count($keys), '?'));
$st = $pdo->prepare("SELECT k, v FROM app_settings WHERE k IN ($placeholders)");
$st->execute($keys);
foreach ($st->fetchAll() as $r) {
    $out[$r['k']] = (string) $r['v'];
}

tw_json($out);
