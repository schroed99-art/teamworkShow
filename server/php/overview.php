<?php
/**
 * Landing page: login-guarded tenant (Mandanten) overview.
 * One card per tenant with a media preview collage, counts and a
 * "Konfigurieren" deep-link into admin.php?tenant=<id>. Media management
 * lives in the admin; this page is view + create + navigate only.
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/status_util.php';
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
// Per tenant: device online/offline rollup (worst status wins).
$devStmt = $pdo->prepare(
    'SELECT TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS s FROM devices WHERE tenant_id = ?'
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

    $devStmt->execute([$t['id']]);
    $statuses = array_map(
        static fn($row): string => tw_device_status($row['s'] === null ? null : (int) $row['s']),
        $devStmt->fetchAll()
    );
    $t['device_count'] = count($statuses);
    $t['dev_status'] = tw_rollup_status($statuses);
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
// Discreet device status dot for a tenant card (title explains it on hover).
function tw_status_dot(string $status, int $deviceCount, int $tenantId): string
{
    $titles = [
        'online'  => 'Gerät online',
        'offline' => 'Gerät offline',
        'alarm'   => 'Gerät seit über 30 min offline',
        'none'    => 'Kein Gerät gekoppelt',
    ];
    $st = $titles[$status] ?? $titles['none'];
    if ($deviceCount > 1) {
        $st .= ' (' . $deviceCount . ' Geräte)';
    }
    return '<span class="statusdot ' . $status . '" data-tenant="' . $tenantId
        . '" title="' . h($st) . '"></span>';
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
<link rel="icon" type="image/png" sizes="64x64" href="assets/favicon.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<style>
  :root { --magenta:#d21a55; --bg:#0f172a; --panel:#1e293b; --panel2:#26344a; --line:#334155;
          --text:#f1f5f9; --dim:#94a3b8; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  body::after { content:""; position:fixed; right:28px; bottom:22px; width:min(360px,32vw); height:min(360px,32vw);
    background:url('assets/logo_mark.png') no-repeat right bottom; background-size:contain;
    opacity:.05; pointer-events:none; z-index:0; }
  .grid, header, .h2 { position:relative; z-index:1; }
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
  .statusdot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:7px;
    vertical-align:middle; background:#5a5f68; box-shadow:0 0 0 3px rgba(255,255,255,.03); }
  .statusdot.online { background:#39d353; box-shadow:0 0 0 3px rgba(57,211,83,.15); }
  .statusdot.offline { background:#9aa0aa; }
  .statusdot.alarm { background:#ff5c72; box-shadow:0 0 0 3px rgba(255,92,114,.2); animation:tw-pulse 1.1s ease-in-out infinite; }
  .statusdot.none { background:#3a3f47; }
  @keyframes tw-pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
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
  .modal input { width:100%; background:#0f172a; border:1px solid var(--line); color:var(--text); border-radius:9px; padding:10px 12px; font-size:14px; }
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
    <a class="pool" href="einstellungen.php" title="Einstellungen &amp; Benutzerverwaltung">Einstellungen</a>
    <a class="pool" href="admin.php#media" title="Medienpool im Admin">Medienpool</a>
  <?php endif; ?>
  <?php include __DIR__ . '/nav_user.php'; ?>
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
        <div class="name"><?= tw_status_dot($t['dev_status'], (int) $t['device_count'], (int) $t['id']) ?><?= h($t['name']) ?></div>
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

// Live status: poll status.php and update the per-tenant dots without a reload.
const DOT_TITLES = {online:'Gerät online', offline:'Gerät offline',
  alarm:'Gerät seit über 30 min offline', none:'Kein Gerät gekoppelt'};
async function pollStatus(){
  try {
    const r = await fetch('status.php', { cache:'no-store' });
    if (!r.ok) return;
    const d = await r.json();
    (d.tenants||[]).forEach(t=>{
      const dot = document.querySelector('.statusdot[data-tenant="'+t.id+'"]');
      if (!dot) return;
      dot.className = 'statusdot ' + t.status;
      let title = DOT_TITLES[t.status] || DOT_TITLES.none;
      if (t.device_count > 1) title += ' (' + t.device_count + ' Geräte)';
      dot.title = title;
    });
  } catch(e){}
}
setInterval(pollStatus, 20000);
document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) pollStatus(); });
</script>
</body>
</html>
