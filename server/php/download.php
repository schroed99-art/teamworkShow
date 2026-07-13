<?php
/**
 * Public install page for the sideloaded Android app.
 *
 * Shows the currently published APK (version, size, checksum) with a big
 * download button and step-by-step install instructions. Meant for a
 * technician on-site: open in any phone browser on the same network, tap
 * download, install. Reads the same app_update.json that app_update.php and
 * scripts/publish-apk.sh use, so it always reflects the latest published APK.
 *
 * Login-gated: reachable only from the (authenticated) admin dashboard. A fresh
 * device must sign in first; unauthenticated visitors are sent to the login page.
 */
require __DIR__ . '/auth.php';
if (tw_role() === null) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/apk_path.php';

$apkName  = 'app-release.apk';
$apkPath  = tw_apk_path();
$metaPath = __DIR__ . '/app_update.json';

$meta = [];
if (is_file($metaPath)) {
    $decoded = json_decode((string) file_get_contents($metaPath), true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}

$available   = is_file($apkPath);
$versionName = $meta['versionName'] ?? '–';
$size        = (int) ($meta['size'] ?? ($available ? filesize($apkPath) : 0));
$sha256      = strtolower((string) ($meta['sha256'] ?? ''));
$sizeMb      = $size > 0 ? number_format($size / 1048576, 1, ',', '.') : '0';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TeamworkShow – App installieren</title>
<style>
  :root { --magenta:#D81B60; --plum:#2a0a1e; --bg:#0a0a0a; --fg:#f4f4f4; --muted:#9a9a9a; }
  * { box-sizing:border-box; }
  body {
    margin:0; min-height:100vh; font-family:-apple-system,Segoe UI,Roboto,sans-serif;
    color:var(--fg); background:radial-gradient(120% 80% at 50% 0%, var(--plum), var(--bg) 70%);
    display:flex; justify-content:center; padding:32px 20px;
  }
  .card { width:100%; max-width:460px; }
  h1 { font-size:26px; margin:0 0 4px; letter-spacing:.5px; }
  h1 span { color:var(--magenta); }
  .sub { color:var(--muted); margin:0 0 28px; font-size:15px; }
  .badge {
    display:inline-block; background:rgba(216,27,96,.15); color:var(--magenta);
    border:1px solid rgba(216,27,96,.4); border-radius:999px; padding:6px 14px;
    font-weight:600; font-size:14px; margin-bottom:24px;
  }
  .dl {
    display:block; text-align:center; text-decoration:none; color:#fff;
    background:var(--magenta); border-radius:14px; padding:18px; font-size:19px;
    font-weight:700; box-shadow:0 8px 24px rgba(216,27,96,.35); margin-bottom:10px;
  }
  .dl:active { transform:translateY(1px); }
  .meta { text-align:center; color:var(--muted); font-size:13px; margin-bottom:32px; }
  ol { padding-left:22px; line-height:1.7; margin:0 0 28px; }
  ol li { margin-bottom:8px; }
  .sha {
    word-break:break-all; font-family:ui-monospace,Menlo,monospace; font-size:11px;
    color:var(--muted); background:rgba(255,255,255,.04); border-radius:8px; padding:10px;
  }
  .warn { color:#ffb020; font-size:14px; }
  hr { border:none; border-top:1px solid rgba(255,255,255,.08); margin:28px 0; }
</style>
</head>
<body>
  <div class="card">
    <h1>Teamwork<span>Show</span></h1>
    <p class="sub">Digital Signage – App installieren</p>

<?php if ($available): ?>
    <div class="badge">Version <?= htmlspecialchars($versionName) ?> &middot; <?= $sizeMb ?> MB</div>

    <a class="dl" href="apk.php" download>⬇︎ App herunterladen</a>
    <p class="meta">Danach in den Downloads antippen, um zu installieren.</p>

    <hr>
    <h3>So installierst du:</h3>
    <ol>
      <li>Falls schon eine ältere Version drauf ist: diese zuerst <strong>deinstallieren</strong>.</li>
      <li>Oben auf <strong>„App herunterladen"</strong> tippen.</li>
      <li>Die geladene Datei in den <strong>Downloads</strong> öffnen.</li>
      <li>Beim Hinweis <strong>„Unbekannte Apps zulassen"</strong> bestätigen.</li>
      <li>Auf <strong>Installieren</strong> tippen → App starten.</li>
      <li>Die App zeigt einen <strong>Kopplungs-Code</strong> – diesen im Dashboard beim Mandanten eingeben.</li>
    </ol>
    <p class="warn">Hinweis: Künftige Updates installiert die App selbstständig – dieser manuelle Schritt ist nur einmalig nötig.</p>

<?php if ($sha256 !== ''): ?>
    <hr>
    <p class="meta" style="margin-bottom:6px">Prüfsumme (SHA-256) zur Kontrolle:</p>
    <div class="sha"><?= htmlspecialchars($sha256) ?></div>
<?php endif; ?>

<?php else: ?>
    <div class="badge">Noch keine App veröffentlicht</div>
    <p class="sub">Es wurde noch keine installierbare Version bereitgestellt. Bitte später erneut versuchen.</p>
<?php endif; ?>
  </div>
</body>
</html>
