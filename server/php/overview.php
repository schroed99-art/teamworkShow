<?php
/**
 * Landing page: login-guarded tenant (Mandanten) overview.
 * One card per tenant with a media preview collage, counts and a
 * "Konfigurieren" deep-link into admin.php?tenant=<id>. Media management
 * lives in the admin; this page is view + create + navigate only.
 */
require __DIR__ . '/auth.php';
$role = tw_role();
if ($role === null) {
    header('Location: login.php');
    exit;
}
// Betrachter may view the tenant list but not configure anything.
$canManage = in_array($role, ['admin', 'koordinator'], true);

$version = '';
$vfile = __DIR__ . '/version.php';
if (is_file($vfile) && preg_match("/'version'\\s*=>\\s*'([^']+)'/", (string) file_get_contents($vfile), $m)) {
    $version = $m[1];
}

$pdo = tw_db();
$tenants = $pdo->query(
    'SELECT t.id, t.name,
            (SELECT COUNT(*) FROM presentations p WHERE p.tenant_id = t.id) AS pres_count
       FROM tenants t ORDER BY t.id'
)->fetchAll();

// Per tenant: distinct slides (media files + file-less weather slides).
$mediaStmt = $pdo->prepare(
    'SELECT DISTINCT s.media_name, s.kind
       FROM slides s JOIN presentations p ON s.presentation_id = p.id
      WHERE p.tenant_id = ? ORDER BY s.position, s.id'
);
$totalMedia = 0;
foreach ($tenants as &$t) {
    $mediaStmt->execute([$t['id']]);
    $t['slides'] = array_map(static function (array $r): array {
        return ['name' => (string) $r['media_name'], 'kind' => (string) ($r['kind'] ?? 'media')];
    }, $mediaStmt->fetchAll());
    // Only real media files count as "Medien"; weather slides are file-less.
    $t['media_count'] = count(array_filter($t['slides'], static fn(array $s): bool => $s['kind'] !== 'weather'));
    $totalMedia += $t['media_count'];
}
unset($t);

function tw_is_video(string $name): bool
{
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'm4v'], true);
}
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES);
}
// Inline sun-and-cloud pictogram for file-less weather slides in the collage.
function tw_weather_pictogram(): string
{
    return '<svg class="wx-pic" viewBox="0 0 64 64" role="img" aria-label="Wetter" xmlns="http://www.w3.org/2000/svg">'
        . '<circle cx="26" cy="24" r="10" fill="#ffb300"/>'
        . '<g stroke="#ffb300" stroke-width="2.4" stroke-linecap="round">'
        . '<path d="M26 6v5"/><path d="M26 37v5"/><path d="M8 24h5"/><path d="M39 24h5"/>'
        . '<path d="M13.2 11.2l3.5 3.5"/><path d="M35.3 33.3l3.5 3.5"/>'
        . '<path d="M38.8 11.2l-3.5 3.5"/><path d="M16.7 33.3l-3.5 3.5"/></g>'
        . '<path d="M22 48a9 9 0 0 1 8.9-9 11 11 0 0 1 21 3.2A8 8 0 0 1 50 58H30a9 9 0 0 1-8-10z" '
        . 'fill="#eceff4" stroke="#c9ced8" stroke-width="1.5"/>'
        . '</svg>';
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teamwork Show — Übersicht</title>
<style>
  :root { --magenta:#d81b60; --bg:#0a0a0a; --panel:#151515; --panel2:#1d1d1d; --line:#2a2a2a;
          --text:#f2f2f2; --dim:#9a9a9a; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  header { padding:18px 24px; border-bottom:1px solid var(--line); display:flex; align-items:center; gap:12px; }
  header h1 { margin:0; font-size:20px; font-weight:600; }
  header h1 span { color:var(--magenta); }
  header .ver { font-size:11px; letter-spacing:.1em; color:var(--dim); border:1px solid var(--line); border-radius:999px; padding:3px 9px; }
  header .stat { margin-left:auto; font-size:12px; color:var(--dim); }
  a.logout, a.pool { color:var(--dim); text-decoration:none; font-size:13px; border:1px solid var(--line); border-radius:8px; padding:6px 12px; }
  a.logout:hover, a.pool:hover { color:var(--text); border-color:var(--magenta); }
  .h2 { padding:18px 24px 4px; font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:var(--dim); }
  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; padding:8px 24px 28px; }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; }
  .collage { display:flex; height:118px; gap:2px; background:#000; }
  .collage .cell { flex:1; position:relative; overflow:hidden; background:var(--panel2); }
  .collage .cell.lead { flex:2; }
  .collage img, .collage video { width:100%; height:100%; object-fit:cover; display:block; }
  .collage .play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                   color:#fff; font-size:16px; text-shadow:0 1px 3px #000; background:rgba(0,0,0,.28); }
  .collage .more { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                   color:#fff; font-size:14px; background:rgba(0,0,0,.5); }
  .collage .empty { flex:1; display:flex; align-items:center; justify-content:center; color:var(--dim); font-size:12px; }
  .collage .cell.weather { display:flex; align-items:center; justify-content:center;
    background:radial-gradient(120% 120% at 50% 0%, #1b2740 0%, #0c1220 100%); }
  .collage .cell.weather .wx-pic { width:66%; height:66%; }
  .body { padding:12px 14px; display:flex; flex-direction:column; gap:2px; }
  .body .name { font-size:15px; font-weight:600; }
  .body .sub { font-size:12px; color:var(--dim); margin-bottom:10px; }
  .body button { background:var(--magenta); color:#fff; border:0; border-radius:8px; padding:9px; font-size:13px; font-weight:600; cursor:pointer; }
  .body button:hover { filter:brightness(1.08); }
  .card.add { border-style:dashed; align-items:center; justify-content:center; min-height:210px; cursor:pointer; color:var(--dim); }
  .card.add:hover { border-color:var(--magenta); color:var(--text); }
  .card.add .plus { font-size:26px; margin-bottom:6px; }
  .empty-all { color:var(--dim); padding:40px 24px; }
  /* branded prompt modal */
  .modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.7); display:none; align-items:center; justify-content:center; z-index:50; }
  .modal-bg.show { display:flex; }
  .modal { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:22px; width:min(400px,92vw); }
  .modal h3 { margin:0 0 12px; }
  .modal input { width:100%; background:#0d0d0d; border:1px solid #333; color:var(--text); border-radius:9px; padding:10px 12px; font-size:14px; }
  .modal .row { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }
  .modal button { border:0; border-radius:9px; padding:9px 14px; font-size:13px; font-weight:600; cursor:pointer; background:var(--magenta); color:#fff; }
  .modal button.ghost { background:transparent; border:1px solid var(--line); color:var(--text); }
</style>
</head>
<body>
<header>
  <h1>Teamwork<span>Show</span></h1>
  <?php if ($version !== ''): ?><span class="ver">v<?= h($version) ?></span><?php endif; ?>
  <span class="stat"><?= count($tenants) ?> Mandanten · <?= $totalMedia ?> Medien</span>
  <?php if ($canManage): ?>
    <a class="pool" href="benutzer.php" title="Benutzerverwaltung">Benutzer</a>
    <a class="pool" href="admin.php#media" title="Medienpool im Admin">Medienpool</a>
  <?php endif; ?>
  <a class="logout" href="change_password.php" title="Eigenes Passwort ändern">Passwort</a>
  <a class="logout" href="login.php?logout=1">Abmelden</a>
</header>

<div class="h2">Mandanten</div>
<?php if (!$tenants): ?>
  <div class="empty-all">Noch keine Mandanten angelegt. Lege unten den ersten an.</div>
<?php endif; ?>
<div class="grid">
  <?php foreach ($tenants as $t): ?>
    <div class="card">
      <div class="collage">
        <?php if (!$t['slides']): ?>
          <div class="empty">Noch keine Medien</div>
        <?php else:
          $preview = array_slice($t['slides'], 0, 4);
          $extra = count($t['slides']) - count($preview);
          foreach ($preview as $idx => $slide):
            $name = $slide['name'];
            $isWeather = $slide['kind'] === 'weather';
            $isVid = !$isWeather && tw_is_video($name);
            $lead = $idx === 0 ? ' lead' : '';
            $showMore = ($idx === count($preview) - 1 && $extra > 0);
        ?>
          <div class="cell<?= $lead ?><?= $isWeather ? ' weather' : '' ?>">
            <?php if ($isWeather): ?>
              <?= tw_weather_pictogram() ?>
            <?php elseif ($isVid): ?>
              <video muted preload="metadata" src="media.php?name=<?= rawurlencode($name) ?>#t=0.1"></video>
              <span class="play">▶</span>
            <?php else: ?>
              <img loading="lazy" src="media.php?name=<?= rawurlencode($name) ?>" alt="">
            <?php endif; ?>
            <?php if ($showMore): ?><span class="more">+<?= $extra ?></span><?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="body">
        <div class="name"><?= h($t['name']) ?></div>
        <div class="sub"><?= (int) $t['pres_count'] ?> Präsentation<?= (int) $t['pres_count'] === 1 ? '' : 'en' ?> · <?= (int) $t['media_count'] ?> Medien</div>
        <?php if ($canManage): ?>
          <button onclick="location.href='admin.php?tenant=<?= (int) $t['id'] ?>'">Konfigurieren</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($canManage): ?>
  <div class="card add" id="addTenant">
    <div class="plus">+</div>
    <div>Neuer Mandant</div>
  </div>
  <?php endif; ?>
</div>

<div class="modal-bg" id="modalBg">
  <div class="modal">
    <h3>Neuer Mandant</h3>
    <input id="tName" placeholder="Name des Mandanten" autocomplete="off">
    <div class="row">
      <button class="ghost" id="mCancel">Abbrechen</button>
      <button id="mOk">Anlegen</button>
    </div>
  </div>
</div>

<script>
const bg = document.getElementById('modalBg');
const nameIn = document.getElementById('tName');
function openModal(){ bg.classList.add('show'); nameIn.value=''; setTimeout(()=>nameIn.focus(),30); }
function closeModal(){ bg.classList.remove('show'); }
const addBtn = document.getElementById('addTenant');
if (addBtn) addBtn.onclick = openModal;
document.getElementById('mCancel').onclick = closeModal;
bg.onclick = e => { if (e.target === bg) closeModal(); };
nameIn.onkeydown = e => { if (e.key==='Enter') create(); if (e.key==='Escape') closeModal(); };
document.getElementById('mOk').onclick = create;
async function create(){
  const name = nameIn.value.trim();
  if (!name) return;
  const r = await fetch('tenants.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({name}) });
  if (r.status === 401) { location.href='login.php'; return; }
  const j = await r.json().catch(()=>({}));
  if (j.id) location.href = 'admin.php?tenant=' + j.id; else location.reload();
}
</script>
</body>
</html>
