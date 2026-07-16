<?php
/**
 * Multi-tenant admin dashboard (session-guarded).
 * Manages tenants, devices, presentations (drag-order + per-slide duration) and
 * per-device widgets. Talks to the CRUD endpoints via same-origin fetch (session cookie).
 *
 * A 'kunde' shares this page but sees a reduced version of it: their own tenant
 * only, content authoring (presentations, slides, media, Laufschrift) and the
 * choice of which presentation runs on their device — no provisioning. Hiding
 * those controls is cosmetic; the endpoints enforce the same limits server-side
 * (see auth.php), so this page never has to be the thing that gets it right.
 */
require __DIR__ . '/auth.php';
$role = tw_role();
if ($role === null) {
    header('Location: login.php');
    exit;
}
if (!in_array($role, ['admin', 'koordinator', 'kunde'], true)) {
    header('Location: overview.php');
    exit;
}
$isKunde = $role === 'kunde';
$actorId = (int) (tw_current_user_id() ?? 0);
$version = '';
$vfile = __DIR__ . '/version.php';
// version.php echoes JSON; read the file's version string cheaply for display.
if (is_file($vfile) && preg_match("/'version'\\s*=>\\s*'([^']+)'/", (string) file_get_contents($vfile), $m)) {
    $version = $m[1];
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teamwork Show — Admin</title>
<link rel="icon" type="image/png" sizes="64x64" href="assets/favicon.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<style>
  :root { --magenta:#d21a55; --bg:#0f172a; --panel:#1e293b; --panel2:#26344a; --line:#334155;
          --text:#f1f5f9; --dim:#94a3b8; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text);
         font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; font-size:14px; }
  body::after { content:""; position:fixed; right:28px; bottom:22px; width:min(360px,32vw); height:min(360px,32vw);
    background:url('assets/logo_mark.png') no-repeat right bottom; background-size:contain;
    opacity:.05; pointer-events:none; z-index:0; }
  header { display:flex; align-items:center; gap:12px; padding:14px 20px; border-bottom:1px solid var(--line);
           position:sticky; top:0; background:var(--bg); z-index:5; }
  header h1 { font-size:18px; margin:0; }
  header h1 span { color:var(--magenta); }
  header .ver { color:var(--dim); font-size:12px; }
  header .spacer { flex:1; }
  a.logout { color:var(--dim); text-decoration:none; font-size:13px; border:1px solid var(--line);
             padding:6px 12px; border-radius:8px; }
  a.logout:hover { color:var(--text); border-color:var(--magenta); }
  .wrap { display:grid; grid-template-columns:260px 1fr; gap:18px; padding:18px; align-items:start;
          position:relative; z-index:1; }
  .panel { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:16px; }
  .panel h2 { margin:0 0 12px; font-size:14px; text-transform:uppercase; letter-spacing:.06em; color:var(--dim); }
  /* Rechte Mandanten-Detailspalte flach (kein umschließender Kasten): Titel + Reiter
     sitzen auf dem Hintergrund, nur der Inhalt steht in Karten — wie einstellungen.php. */
  #detail { background:transparent; border:0; border-radius:0; padding:0; }
  ul.list { list-style:none; margin:0; padding:0; }
  ul.list li { display:flex; align-items:center; gap:8px; padding:9px 10px; border-radius:9px; cursor:pointer; }
  ul.list li:hover { background:var(--panel2); }
  ul.list li.active { background:var(--magenta); color:#fff; }
  ul.list li .name { flex:1; }
  .row { display:flex; gap:8px; align-items:center; }
  .row.wrap2 { flex-wrap:wrap; }
  input, select, textarea { background:#0f172a; border:1px solid var(--line); color:var(--text); border-radius:9px;
                            padding:9px 11px; font-size:13px; }
  input:focus, select:focus, textarea:focus { outline:none; border-color:var(--magenta); }
  input.grow { flex:1; }
  button { border:0; border-radius:9px; padding:9px 13px; font-size:13px; font-weight:600; cursor:pointer;
           background:var(--magenta); color:#fff; }
  button.ghost { background:transparent; border:1px solid var(--line); color:var(--text); }
  button.ghost:hover { border-color:var(--magenta); }
  button.sm { padding:5px 9px; font-size:12px; }
  /* Reiter-Optik (wie einstellungen.php): Unterstrich statt Pille. */
  .tabs { display:flex; gap:4px; border-bottom:1px solid var(--line); margin-bottom:14px; flex-wrap:wrap; }
  button.tab { background:transparent; border:0; border-bottom:2px solid transparent; border-radius:0;
    color:var(--dim); font-size:13px; font-weight:600; padding:9px 16px; cursor:pointer; }
  button.tab:hover { color:var(--text); filter:none; }
  button.tab.active { color:var(--text); border-bottom-color:var(--magenta); }
  button.eye { background:transparent; border:1px solid var(--line); color:var(--dim);
    padding:4px 8px; border-radius:8px; display:inline-flex; align-items:center; cursor:pointer; }
  button.eye:hover:not(:disabled) { border-color:var(--magenta); color:var(--text); }
  button.eye.on { color:#39d353; border-color:#1c5c2e; }
  button.eye:disabled { opacity:.4; cursor:not-allowed; }
  /* "aktiv" ist ein positiver Zustand -> grün (nicht magenta). */
  .badge-on { font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
    color:#39d353; border:1px solid #1c5c2e; background:#0e1f13; border-radius:6px; padding:1px 6px; margin-left:6px; }
  .statuspill { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:600;
    padding:2px 9px; border-radius:999px; border:1px solid var(--line); }
  .statuspill .dot { width:8px; height:8px; border-radius:50%; background:currentColor; flex:none; }
  .statuspill.online { color:#39d353; border-color:#1c5c2e; background:#0e1f13; }
  .statuspill.offline { color:#9aa0aa; border-color:#3a3f47; background:#15171b; }
  .statuspill.never { color:#9aa0aa; border-color:#3a3f47; background:#15171b; }
  .statuspill.alarm { color:#ff5c72; border-color:#5a2230; background:#210e13; }
  .statuspill.alarm .dot { animation:tw-pulse 1.1s ease-in-out infinite; }
  @keyframes tw-pulse { 0%,100%{opacity:1} 50%{opacity:.25} }
  button:hover { filter:brightness(1.08); }
  .card { background:var(--panel2); border:1px solid var(--line); border-radius:12px; padding:14px; margin-bottom:14px; }
  /* App-Installation + Koppeln nebeneinander; auf schmalen Screens untereinander. */
  .top-tiles { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; align-items:start; }
  @media (max-width:900px){ .top-tiles { grid-template-columns:1fr; } }
  .card h3 { margin:0 0 10px; font-size:14px; }
  .muted { color:var(--dim); font-size:12px; }
  .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  label.f { display:block; font-size:11px; color:var(--dim); margin:8px 0 4px; }
  .slides li { display:flex; align-items:center; gap:8px; padding:8px; background:#0e0e0e; border:1px solid var(--line);
               border-radius:9px; margin-bottom:7px; }
  .slides li.drag { opacity:.4; }
  .slides .handle { cursor:grab; color:var(--dim); user-select:none; }
  .slides .mname { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .slides input.dur { width:92px; }
  .slides .thumb { position:relative; flex:none; width:64px; height:40px; border-radius:6px; overflow:hidden;
                   border:1px solid var(--line); background:#000; cursor:pointer; }
  .slides .thumb img, .slides .thumb video { width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; }
  .slides .thumb:hover { border-color:var(--magenta); }
  .slides .thumb .play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                         color:#fff; font-size:15px; text-shadow:0 1px 3px #000; background:rgba(0,0,0,.25); }
  /* lightbox for slide previews */
  .lb-bg { position:fixed; inset:0; background:rgba(0,0,0,.85); display:none; align-items:center; justify-content:center;
           z-index:70; padding:24px; }
  .lb-bg.show { display:flex; }
  .lb-box { position:relative; max-width:92vw; max-height:88vh; display:flex; flex-direction:column; gap:10px; align-items:center; }
  .lb-box img, .lb-box video { max-width:92vw; max-height:80vh; border-radius:10px; border:1px solid var(--line); background:#000; }
  .lb-cap { color:var(--dim); font-size:12px; }
  .lb-close { position:absolute; top:18px; right:18px; z-index:1; width:38px; height:38px; border-radius:50%;
              background:var(--magenta); color:#fff; font-size:18px; line-height:38px; text-align:center; cursor:pointer; border:0; }
  .lb-close:hover { filter:brightness(1.1); }
  /* --- Vorschau: schematischer Screen-Player (Inhalt/Format/Ablauf, nicht Auflösung) --- */
  .pv-bg { position:fixed; inset:0; background:rgba(0,0,0,.92); display:none; flex-direction:column;
           align-items:center; justify-content:center; gap:14px; z-index:60; }
  .pv-bg.show { display:flex; }
  .pv-close { position:absolute; top:18px; right:18px; z-index:2; width:38px; height:38px; border-radius:50%;
              border:1px solid var(--line); background:var(--panel); color:var(--text); cursor:pointer; font-size:16px; }
  .pv-close:hover { filter:brightness(1.1); }
  .pv-frame { position:relative; background:#000; border:2px solid var(--line); border-radius:14px; overflow:hidden;
              box-shadow:0 20px 60px rgba(0,0,0,.6); }
  .pv-frame.portrait  { height:min(74vh,860px); aspect-ratio:9/16; }
  .pv-frame.landscape { width:min(92vw,1200px); aspect-ratio:16/9; max-height:78vh; }
  .pv-stage { position:absolute; inset:0; display:flex; }
  .pv-zone { position:relative; overflow:hidden; background:#000; min-width:0; min-height:0; container-type:size; }
  .pv-zsep { background:var(--magenta); flex:0 0 auto; }
  .pv-media { position:absolute; inset:0; width:100%; height:100%; object-fit:contain; background:#000; display:block; }
  .pv-news { position:absolute; inset:0; display:flex; flex-direction:column; justify-content:center; gap:.4em;
             padding:7%; text-align:center; background:#0b1220; color:#f1f5f9; }
  .pv-news .t { font-weight:800; color:#fda4b8; line-height:1.15; font-size:clamp(13px,7cqw,52px); }
  .pv-news .b { line-height:1.35; white-space:pre-line; opacity:.95; font-size:clamp(10px,4.4cqw,34px); }
  .pv-wx { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center;
           gap:.35em; color:#fff; background:#0d2233; }
  .pv-wx .bg { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; opacity:.45; }
  .pv-wx .ic { font-size:clamp(30px,16cqw,110px); position:relative; }
  .pv-wx .loc { font-weight:700; font-size:clamp(12px,5cqw,34px); position:relative; }
  .pv-wx .hint { opacity:.8; font-size:clamp(9px,3.4cqw,22px); position:relative; }
  .pv-empty { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:var(--dim); font-size:13px; }
  .pv-ticker { position:absolute; left:0; right:0; bottom:0; overflow:hidden; white-space:nowrap; padding:5px 0; z-index:3; }
  .pv-ticker span { display:inline-block; padding-left:100%; animation:pv-marq linear infinite; }
  @keyframes pv-marq { from{transform:translateX(0)} to{transform:translateX(-100%)} }
  .pv-cap { color:var(--dim); font-size:12px; text-align:center; max-width:90vw; }
  .tag { font-size:11px; color:var(--dim); }
  .pair { font-family:ui-monospace,monospace; color:var(--magenta); }
  /* branded confirm modal */
  .modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.7); display:none; align-items:center; justify-content:center; z-index:50; }
  .modal-bg.show { display:flex; }
  .modal { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:22px; width:min(400px,92vw); }
  .modal h3 { margin:0 0 8px; }
  /* pre-line so a generated password can be shown on its own line */
  .modal p { color:var(--dim); margin:0 0 18px; white-space:pre-line; }
  .modal .row { justify-content:flex-end; }
  .toast { position:fixed; bottom:18px; left:50%; transform:translateX(-50%); background:var(--panel2);
           border:1px solid var(--magenta); color:var(--text); padding:10px 16px; border-radius:10px; display:none; z-index:60; }
  .toast.show { display:block; }
  /* media pool */
  .drop { margin-top:6px; padding:16px; border:2px dashed var(--line); border-radius:10px;
          display:flex; align-items:center; gap:14px; flex-wrap:wrap; transition:border-color .15s, background .15s; }
  .drop.over { border-color:var(--magenta); background:rgba(210,26,85,.10); }
  #upStatus { margin-left:auto; color:var(--magenta); font-size:13px; }
  .poolGrid { display:grid; gap:14px; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); margin-top:14px; }
  .pcard { position:relative; background:#0e0e0e; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
  .pthumb { aspect-ratio:9/16; background:#000; cursor:pointer; position:relative; display:flex; align-items:center; justify-content:center; }
  .pthumb img, .pthumb video { width:100%; height:100%; object-fit:contain; }
  .pthumb:hover { outline:1px solid var(--magenta); outline-offset:-1px; }
  .pthumb .play { position:absolute; color:#fff; font-size:30px; text-shadow:0 1px 4px #000; }
  .pdel { position:absolute; top:6px; right:6px; width:28px; height:28px; border:0; border-radius:50%;
          background:rgba(0,0,0,.65); color:#fff; font-size:15px; cursor:pointer; opacity:0; transition:opacity .15s; }
  .pcard:hover .pdel { opacity:1; }
  .pdel:hover { background:var(--magenta); }
  .pmeta { padding:8px 10px; }
  .pmeta .pn { font-size:12px; word-break:break-all; }
  .pmeta .psub { font-size:11px; color:var(--dim); margin-top:3px; display:flex; gap:8px; }
  .pill { border:1px solid var(--line); border-radius:6px; padding:1px 6px; }
  .poolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:16px 0 4px; }
  .poolbar .grow { flex:1; min-width:180px; }
  .poolbar select { min-width:150px; }
  .pgroup { margin-top:16px; }
  .pgroup h3 { font-size:12px; color:var(--dim); text-transform:uppercase; letter-spacing:.05em;
               margin:0 0 10px; border-bottom:1px solid var(--line); padding-bottom:6px; }
  .passign { width:100%; margin-top:7px; font-size:12px; padding:5px 7px; }
  /* weather-layout editor */
  .wx-bg { position:fixed; inset:0; background:rgba(0,0,0,.78); display:none; align-items:center; justify-content:center; z-index:80; padding:20px; }
  .wx-bg.show { display:flex; }
  .wx-panel { background:var(--panel); border:1px solid var(--line); border-radius:16px; width:min(960px,96vw);
              max-height:92vh; overflow:auto; padding:20px; display:grid; grid-template-columns:1fr 300px; gap:22px; }
  .wx-panel h3 { margin:0 0 2px; }
  .wx-sec { border:1px solid var(--line); border-radius:10px; padding:10px 12px; margin-top:10px; }
  .wx-sec .hd { display:flex; align-items:center; gap:8px; font-weight:600; }
  .wx-sec .ctl { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:8px; }
  .wx-sec .ctl label { font-size:11px; color:var(--dim); display:flex; flex-direction:column; gap:3px; }
  .wx-sec input[type=number] { width:78px; }
  .wx-sec input[type=color] { width:44px; height:34px; padding:2px; }
  .wx-side { position:sticky; top:0; align-self:start; }
  .wx-prev { position:relative; width:100%; aspect-ratio:9/16; background:#000; border:1px solid var(--line);
             border-radius:12px; overflow:hidden; }
  .wx-prev.land { aspect-ratio:16/9; }
  .wx-prev .bg { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
  .wx-prev .scrim { position:absolute; inset:0; background:#000; }
  .wx-prev .el { position:absolute; color:#fff; white-space:nowrap; text-shadow:0 1px 5px #000; font-weight:600; line-height:1.1; }
  .wx-prev .clock { border-radius:50%; background:rgba(232,232,232,.92); display:flex; align-items:center; justify-content:center; text-shadow:none; }
  .wx-txtrow { display:flex; gap:6px; align-items:center; margin-top:6px; flex-wrap:wrap; }
  @media (max-width:760px) { .wx-panel { grid-template-columns:1fr; } }
  /* Free-form zone editor (staff only) */
  .ze-wrap { margin-top:12px; border:1px solid var(--line); border-radius:12px; padding:14px; }
  .ze-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px; }
  .ze-tabs button { font-size:12px; padding:5px 10px; }
  .ze-canvas-wrap { display:flex; justify-content:center; }
  .ze-canvas { background:#000; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
  .ze-canvas.portrait { width:210px; aspect-ratio:9/16; }
  .ze-canvas.landscape { width:100%; max-width:430px; aspect-ratio:16/9; }
  .ze-split { display:flex; width:100%; height:100%; position:relative; }
  .ze-child { position:relative; min-width:0; min-height:0; display:flex; }
  .ze-leaf { position:relative; flex:1; margin:3px; border:1px solid var(--magenta); border-radius:8px;
             background:rgba(210,26,85,.08); display:flex; flex-direction:column; min-width:0; min-height:0; overflow:hidden; }
  .ze-leaf .ze-src { font-size:11px; margin:4px 4px 2px; padding:3px 5px; }
  .ze-leaf .ze-btns { display:flex; gap:3px; margin:0 4px 4px; }
  .ze-leaf .ze-btns button { font-size:11px; padding:2px 6px; line-height:1.15; }
  .ze-splitctl { position:absolute; left:50%; bottom:4px; transform:translateX(-50%); z-index:2;
                 display:flex; gap:4px; align-items:center; background:rgba(15,23,42,.9);
                 border:1px solid var(--line); border-radius:7px; padding:2px 5px; }
  .ze-splitctl button { font-size:11px; padding:1px 6px; }
  .ze-splitctl input[type=range] { width:74px; }
</style>
<?php require_once __DIR__ . '/brand_partials.php'; echo tw_brand_css(); ?>
</head>
<body>
<header>
  <h1>Teamwork<span>Show</span></h1>
  <span class="ver"><?= $version !== '' ? 'v' . htmlspecialchars($version) : '' ?></span>
  <?= tw_area_badge($isKunde) ?>
  <span class="spacer"></span>
  <a class="logout" href="overview.php">← Übersicht</a>
  <?php if (!$isKunde): ?><a class="logout" href="einstellungen.php">Einstellungen</a><?php endif; ?>
  <?php include __DIR__ . '/nav_user.php'; ?>
</header>
<?= tw_brandby() ?>

<div class="wrap">
  <div class="panel">
    <h2><?= $isKunde ? 'Mein Bereich' : 'Mandanten' ?></h2>
    <ul class="list" id="tenantList"></ul>
    <?php if (!$isKunde): ?>
    <div class="row" style="margin-top:12px">
      <input class="grow" id="newTenant" placeholder="Neuer Mandant…">
      <button class="sm" id="addTenant">+</button>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel" id="detail">
    <h2 id="detailTitle"><?= $isKunde ? 'Wird geladen…' : 'Bitte einen Mandanten wählen' ?></h2>
    <div id="detailBody"></div>
  </div>
</div>

<div class="panel" id="media" style="display:none; margin:18px">
  <h2>Medienpool <span class="muted" id="poolCount"></span></h2>
  <p class="muted" style="margin:-6px 0 10px"><?= $isKunde
      ? 'Ihre Bilder und Videos. Ihre Präsentationen wählen aus diesem Pool.'
      : 'Von allen Mandanten geteilt. Präsentationen wählen aus diesem Pool.' ?></p>
  <div class="drop" id="drop">
    <button class="sm" id="pickBtn">Dateien wählen</button>
    <input type="file" id="fileInput" accept=".jpg,.jpeg,.png,.webp,.mp4" multiple hidden>
    <span class="muted">Dateien hierher ziehen oder wählen — <b style="color:var(--text)">gleicher Name = austauschen</b>, neuer Name = hinzufügen. (jpg, jpeg, png, webp, mp4)</span>
    <span id="upStatus"></span>
  </div>
  <div class="poolbar">
    <input class="grow" id="poolSearch" placeholder="<?= $isKunde ? 'Bildname…' : 'Bildname, Mandant oder Projektnummer…' ?>">
    <select id="poolTenant"<?= $isKunde ? ' style="display:none"' : '' ?>></select>
    <select id="poolStand"></select>
  </div>
  <div id="poolGroups"></div>
</div>

<div class="modal-bg" id="modalBg">
  <div class="modal">
    <h3 id="modalTitle">Bestätigen</h3>
    <p id="modalText"></p>
    <div class="row">
      <button class="ghost" id="modalCancel">Abbrechen</button>
      <button id="modalOk">OK</button>
    </div>
  </div>
</div>
<div class="toast" id="toast"></div>

<div class="lb-bg" id="lbBg">
  <button class="lb-close" id="lbClose" title="Schließen">✕</button>
  <div class="lb-box">
    <div id="lbStage"></div>
    <div class="lb-cap" id="lbCap"></div>
  </div>
</div>

<div class="pv-bg" id="pvBg">
  <button class="pv-close" id="pvClose" title="Schließen">✕</button>
  <div id="pvFrameWrap"></div>
  <div class="pv-cap" id="pvCap"></div>
</div>

<script>
const API = {
  async call(url, method='GET', body=null) {
    const opt = { method, headers:{} };
    if (body) { opt.headers['Content-Type']='application/json'; opt.body=JSON.stringify(body); }
    const r = await fetch(url, opt);
    if (r.status === 401) { location.href = 'login.php'; throw new Error('unauthorized'); }
    let data = {}; try { data = await r.json(); } catch(e){}
    if (!r.ok) throw new Error(data.error || ('HTTP '+r.status));
    return data;
  }
};
const $ = s => document.querySelector(s);
let tenants = [], activeTenant = null, media = [];
const DEEP_TENANT = <?= (int) ($_GET['tenant'] ?? 0) ?>;
// Customer mode: authoring only. Every control this hides is also refused by the
// endpoints, so IS_KUNDE is about not showing a customer a door they can't open.
const IS_KUNDE = <?= $isKunde ? 'true' : 'false' ?>;
const CURRENT_UID = <?= $actorId ?>;   // to keep someone from locking themselves out
function fmtSize(b){ if(b==null||b<0)return '–'; if(b<1024)return b+' B'; if(b<1048576)return (b/1024).toFixed(0)+' KB'; return (b/1048576).toFixed(1)+' MB'; }

function toast(msg){ const t=$('#toast'); t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),2200); }
function confirmDialog(title, text){
  return new Promise(res=>{
    $('#modalTitle').textContent=title; $('#modalText').textContent=text;
    const bg=$('#modalBg'); bg.classList.add('show');
    const done=v=>{ bg.classList.remove('show'); $('#modalOk').onclick=null; $('#modalCancel').onclick=null; res(v); };
    $('#modalOk').onclick=()=>done(true); $('#modalCancel').onclick=()=>done(false);
  });
}
const esc = s => (s??'').toString().replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

// Eye (open = active/shown) / eye-off (crossed = hidden) icon for presentation toggle.
// Device online/offline pill from the server-computed status + seconds_since_seen.
function agoHuman(s){
  if(s===null||s===undefined) return 'nie';
  if(s<0) s=0;
  if(s<60) return 'vor '+s+' s';
  if(s<3600) return 'vor '+Math.floor(s/60)+' min';
  if(s<86400) return 'vor '+Math.floor(s/3600)+' h';
  return 'vor '+Math.floor(s/86400)+' Tg.';
}
function statusPill(d){
  const st=d.status||(d.last_seen?'offline':'never');
  const secs=d.seconds_since_seen;
  const map={
    online:{cls:'online',label:'online'},
    offline:{cls:'offline',label:'offline · '+agoHuman(secs)},
    alarm:{cls:'alarm',label:'⚠ offline seit '+agoHuman(secs)},
    never:{cls:'never',label:'nie gesehen'}
  };
  const m=map[st]||map.never;
  return `<span class="statuspill ${m.cls}" data-dev="${d.id}"><span class="dot"></span>${m.label}</span>`;
}
// Live status: refresh the visible device pills from status.php without a reload.
async function pollDeviceStatus(){
  const pills=document.querySelectorAll('.statuspill[data-dev]');
  if(!pills.length) return;
  try{
    const r=await fetch('status.php',{cache:'no-store'});
    if(!r.ok) return;
    const d=await r.json();
    const map={}; (d.devices||[]).forEach(x=>map[String(x.id)]=x);
    pills.forEach(p=>{
      const x=map[p.getAttribute('data-dev')];
      if(!x) return;
      p.outerHTML=statusPill({id:p.getAttribute('data-dev'),status:x.status,seconds_since_seen:x.seconds_since_seen});
    });
  }catch(e){}
}
setInterval(pollDeviceStatus, 20000);
document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) pollDeviceStatus(); });

function eyeSvg(open){
  const stroke='stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"';
  return open
    ? `<svg width="17" height="17" viewBox="0 0 24 24" ${stroke}><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>`
    : `<svg width="17" height="17" viewBox="0 0 24 24" ${stroke}><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20C5 20 1 12 1 12a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
}

// Media preview helpers (thumbnails + lightbox popup) --------------------------
const VIDEO_EXT = ['mp4','webm','mov','m4v'];
const isVideo = name => VIDEO_EXT.includes((name.split('.').pop()||'').toLowerCase());
const mediaUrl = name => 'media.php?name=' + encodeURIComponent(name);

function thumbHtml(name){
  if (isVideo(name)) {
    // preload=metadata + a time fragment renders the first frame as a poster.
    return `<span class="thumb" data-preview="${esc(name)}" title="${esc(name)} – Vorschau">
              <video muted preload="metadata" src="${mediaUrl(name)}#t=0.1"></video>
              <span class="play">▶</span></span>`;
  }
  return `<span class="thumb" data-preview="${esc(name)}" title="${esc(name)} – Vorschau">
            <img loading="lazy" src="${mediaUrl(name)}" alt=""></span>`;
}

function openLightbox(name){
  const stage=$('#lbStage'), cap=$('#lbCap');
  stage.innerHTML = isVideo(name)
    ? `<video src="${mediaUrl(name)}" controls autoplay playsinline></video>`
    : `<img src="${mediaUrl(name)}" alt="">`;
  cap.textContent = name;
  $('#lbBg').classList.add('show');
}
function closeLightbox(){
  $('#lbBg').classList.remove('show');
  $('#lbStage').innerHTML=''; // stop any playing video
}
$('#lbClose').onclick = closeLightbox;
$('#lbBg').onclick = e => { if (e.target === $('#lbBg')) closeLightbox(); };
document.addEventListener('keydown', e => { if (e.key==='Escape') closeLightbox(); });

// --- Vorschau: schematischer Screen-Player -------------------------------------
// Rendert denselben Ablauf, den das Gerät spielt: Format (hoch/quer), Zonen-Split,
// je Zone eine eigene Timer-Schleife über die Slides. Es geht bewusst um den
// optischen Inhalt (Bilder/Videos/Nachrichten/Wetter) und die Aufteilung, nicht um
// die echte Auflösung des Zielgeräts. Teilt sich mediaUrl/esc/isVideo mit der Lightbox.
let pvTimers = [];
function pvStop(){ pvTimers.forEach(clearTimeout); pvTimers=[]; }
function pvClose(){ pvStop(); $('#pvBg').classList.remove('show'); $('#pvFrameWrap').innerHTML=''; }
// Android speichert Farben als #AARRGGBB (Alpha zuerst); CSS erwartet rgba().
function pvColor(h, fb){
  if(typeof h!=='string') return fb;
  const m=h.replace('#','');
  if(m.length===8){ const a=(parseInt(m.slice(0,2),16)/255).toFixed(2);
    return `rgba(${parseInt(m.slice(2,4),16)},${parseInt(m.slice(4,6),16)},${parseInt(m.slice(6,8),16)},${a})`; }
  if(m.length===6) return '#'+m;
  return fb;
}
function pvSlideHtml(s, wx){
  const kind = s.kind||'media';
  if (kind==='news')
    return `<div class="pv-news"><div class="t">${esc(s.title||s.text_title||'')}</div>`+
           `<div class="b">${esc(s.body||s.text_body||'')}</div></div>`;
  if (kind==='weather'){
    const bg = wx && wx.asset ? `<img class="bg" src="${mediaUrl(wx.asset)}" alt="">` : '';
    return `<div class="pv-wx">${bg}<div class="ic">🌤</div>`+
           `<div class="loc">${esc((wx&&wx.loc)||'Wetter')}</div>`+
           `<div class="hint">Vorhersage erscheint am Gerät</div></div>`;
  }
  const name = s.name||s.media_name||'';
  if (!name) return `<div class="pv-empty">—</div>`;
  return isVideo(name)
    ? `<video class="pv-media" src="${mediaUrl(name)}" autoplay muted loop playsinline></video>`
    : `<img class="pv-media" src="${mediaUrl(name)}" alt="">`;
}
// Läuft die Slides einer Zone durch; nächster Wechsel nach duration_ms.
function pvPlayZone(zoneEl, slides, wx){
  if (!slides || !slides.length){ zoneEl.innerHTML='<div class="pv-empty">Keine Slides</div>'; return; }
  let i=0;
  const step=()=>{
    const s=slides[i%slides.length];
    zoneEl.innerHTML=pvSlideHtml(s, wx);
    i++;
    pvTimers.push(setTimeout(step, Math.max(1000, (+s.duration_ms)||8000)));
  };
  step();
}
// cfg: { format, zones:{axis,split,company,customer}|null, items, wx:{loc,asset}, ticker:{on,text,color,bg} }
// Recursively render a resolved custom zone tree (Vollausbau): a split lays its
// weighted children along the axis with a magenta separator between them; a leaf is
// one .pv-zone playing its own slides. Mirrors the app's recursive Stage tree.
function pvRenderNode(parent, node, wx){
  if (node && node.children){
    const cols = node.axis==='cols';
    parent.style.display='flex';
    parent.style.flexDirection = cols?'row':'column';
    node.children.forEach((ch,i)=>{
      if (i>0){ const sep=document.createElement('div'); sep.className='pv-zsep';
                sep.style.cssText = cols?'width:2px':'height:2px'; parent.appendChild(sep); }
      const w=document.createElement('div');
      w.style.cssText='position:relative;min-width:0;min-height:0;display:flex;flex:'+(ch.size||1);
      parent.appendChild(w);
      pvRenderNode(w, ch.node, wx);
    });
  } else {
    const z=document.createElement('div'); z.className='pv-zone'; z.style.flex='1';
    parent.appendChild(z);
    pvPlayZone(z, (node&&node.slides)||[], wx);
  }
}
function pvOpen(cfg, caption){
  pvStop();
  const wrap=$('#pvFrameWrap'); wrap.innerHTML='';
  const landscape = cfg.format==='landscape' || cfg.format==='tablet';
  const frame=document.createElement('div');
  frame.className='pv-frame '+(landscape?'landscape':'portrait');
  const stage=document.createElement('div'); stage.className='pv-stage';
  frame.appendChild(stage);
  const zone=()=>{ const z=document.createElement('div'); z.className='pv-zone'; return z; };

  if (cfg.tree){                              // custom: free-form zone tree (Vollausbau)
    pvRenderNode(stage, cfg.tree, cfg.wx);
  } else if (cfg.zones){
    const z=cfg.zones, cols=z.axis==='cols';
    stage.style.flexDirection = cols?'row':'column';
    const comp=zone(), cust=zone();          // company first = oben/links, wie im Gerät
    comp.style.flex=z.split; cust.style.flex=(100-z.split);
    const sep=document.createElement('div'); sep.className='pv-zsep';
    sep.style.cssText = cols?'width:2px':'height:2px';
    stage.append(comp, sep, cust);
    pvPlayZone(comp, z.company, cfg.wx);
    pvPlayZone(cust, z.customer, cfg.wx);
  } else {
    const one=zone(); one.style.flex='1'; stage.appendChild(one);
    pvPlayZone(one, cfg.items, cfg.wx);
  }

  if (cfg.ticker && cfg.ticker.on && (cfg.ticker.text||'').trim()){
    const tk=document.createElement('div'); tk.className='pv-ticker';
    tk.style.background = pvColor(cfg.ticker.bg, 'rgba(0,0,0,.55)');
    const sp=document.createElement('span'); sp.textContent=cfg.ticker.text;
    sp.style.color = pvColor(cfg.ticker.color, '#fff');
    sp.style.animationDuration = Math.max(6, Math.round((cfg.ticker.text.length+20)*0.28))+'s';
    tk.appendChild(sp); frame.appendChild(tk);
  }

  wrap.appendChild(frame);
  $('#pvCap').textContent = caption||'';
  $('#pvBg').classList.add('show');
}
// Geräte-Vorschau: exakt die Playlist, die dieser Bildschirm spielt (öffentlicher Endpoint).
async function pvDevice(code, label){
  try{
    const pl=await (await fetch('playlist.php?device='+encodeURIComponent(code),{cache:'no-store'})).json();
    const custom = pl.zones && pl.zones.mode==='custom';
    const tree = custom ? pl.zones.tree : null;
    const zn = (pl.zones && !custom) ? {axis:pl.zones.axis, split:pl.zones.split,
                           company:pl.zones.company||[], customer:pl.zones.customer||[]} : null;
    const fmt = (pl.device&&pl.device.display_format)||'portrait';
    pvOpen({
      format: fmt, tree, zones: zn, items: pl.items||[],
      wx: { loc:(pl.widgets&&pl.widgets.weather_location)||'', asset:(pl.weather_asset&&pl.weather_asset.name)||'' },
      ticker: pl.widgets ? { on:!!pl.widgets.notices_enabled, text:pl.widgets.notices_text||'',
                             color:pl.widgets.notices_color, bg:pl.widgets.notices_bg } : {on:false}
    }, (label||'Gerät')+' · '+(custom?'Freie Zonen':(pl.zones?('Split '+pl.zones.axis+' '+pl.zones.split+'/'+(100-pl.zones.split)):'Einzelfläche'))+' · '+fmt);
  }catch(e){ toast('Vorschau fehlgeschlagen'); }
}
// Präsentations-Vorschau: das gespeicherte Board als Vollbild-Einzelshow.
async function pvPresentation(id, name){
  try{
    const full=(await API.call('presentations.php?id='+id)).presentation;
    const items=(full.slides||[]).map(s=>({name:s.media_name, kind:s.kind||'media',
      title:s.text_title, body:s.text_body, duration_ms:s.duration_ms}));
    pvOpen({ format:'portrait', zones:null, items, wx:{loc:'',asset:''}, ticker:{on:false} },
           'Präsentation: '+(name||'')+' · Einzelfläche (gespeicherter Stand)');
  }catch(e){ toast('Vorschau fehlgeschlagen'); }
}
$('#pvClose').onclick = pvClose;
$('#pvBg').onclick = e => { if (e.target === $('#pvBg')) pvClose(); };
document.addEventListener('keydown', e => { if (e.key==='Escape' && $('#pvBg').classList.contains('show')) pvClose(); });

async function loadTenants(){
  tenants = (await API.call('tenants.php')).tenants || [];
  const ul=$('#tenantList'); ul.innerHTML='';
  tenants.forEach(t=>{
    const li=document.createElement('li');
    if (activeTenant && t.id===activeTenant.id) li.className='active';
    li.innerHTML = `<span class="name">${esc(t.name)}</span>`
      + (IS_KUNDE?'':`<button class="ghost sm" data-x="1">✎</button>`);
    li.querySelector('.name').onclick=()=>selectTenant(t);
    li.querySelector('[data-x]')?.addEventListener('click', async(e)=>{ e.stopPropagation();
      const name=await promptInline('Mandant umbenennen', t.name); if(name===null)return;
      await API.call('tenants.php','PUT',{id:t.id,name}); toast('Gespeichert'); await loadTenants(); });
    ul.appendChild(li);
  });
}

// tiny inline prompt reusing the modal is overkill; use a minimal branded prompt
function promptInline(title, val=''){
  return new Promise(res=>{
    $('#modalTitle').textContent=title;
    $('#modalText').innerHTML = `<input id="promptInput" class="grow" style="width:100%" value="${esc(val)}">`;
    const bg=$('#modalBg'); bg.classList.add('show');
    setTimeout(()=>{ const i=$('#promptInput'); if(i){i.focus(); i.select();} },30);
    const done=v=>{ bg.classList.remove('show'); $('#modalText').textContent=''; $('#modalOk').onclick=null; $('#modalCancel').onclick=null; res(v); };
    $('#modalOk').onclick=()=>{ const i=$('#promptInput'); done(i?i.value.trim():''); };
    $('#modalCancel').onclick=()=>done(null);
  });
}

/**
 * The media folder is physically shared. playlist.php is the device endpoint and
 * lists every file in it; media_meta.php is tenant-filtered. For a customer we
 * intersect the two, so the pool and the slide picker only ever offer their own
 * files instead of every tenant's filenames.
 */
async function scopeToOwn(items){
  if (!IS_KUNDE) return items;
  try {
    const m = await API.call('media_meta.php');
    const own = new Set((m.items||[]).map(i=>i.filename));
    return items.filter(i=>own.has(i.name));
  } catch(e){ return []; }
}
async function loadMedia(){
  try {
    const items = await scopeToOwn((await API.call('playlist.php')).items||[]);
    media = items.map(i=>i.name);
  } catch(e){ media=[]; }
}

// Every presentation in the system, for the company zone — that one may come from
// another tenant (it carries OUR advertising), so it is not the tenant's list.
let allPres = [];
async function selectTenant(t){
  activeTenant=t; await loadTenants();
  $('#detailTitle').textContent = t.name;
  const [devs, pres] = await Promise.all([
    API.call('devices.php?tenant_id='+t.id),
    API.call('presentations.php?tenant_id='+t.id),
  ]);
  if(!IS_KUNDE){
    try { allPres = (await API.call('presentations.php')).presentations||[]; } catch(e){ allPres=[]; }
  }
  renderDetail(t, devs.devices||[], pres.presentations||[]);
}

<?php if (!$isKunde): ?>
/**
 * Source <option>s shared by the legacy company-zone select and every leaf of the
 * free-form editor: the customer's own presentation plus every presentation in the
 * system, grouped by tenant (a zone may carry our advertising from another tenant).
 */
function zoneSourceGroups(sel, includeCustomer){
  const byTenant={};
  allPres.forEach(p=>{ (byTenant[p.tenant_id]=byTenant[p.tenant_id]||[]).push(p); });
  const groups=Object.keys(byTenant).map(tid=>{
    const tn=(tenants.find(x=>String(x.id)===String(tid))||{}).name||('Mandant '+tid);
    return `<optgroup label="${esc(tn)}">`
      + byTenant[tid].map(p=>`<option value="${p.id}"${String(p.id)===String(sel)?' selected':''}>${esc(p.name)}</option>`).join('')
      + `</optgroup>`;
  }).join('');
  const cust=includeCustomer
    ? `<option value="customer"${sel==='customer'?' selected':''}>Kunde (eigene Präsentation)</option>` : '';
  return cust+groups;
}

/**
 * Zone controls for a device (staff only). Three modes:
 *   single  – only the customer's presentation, full screen.
 *   split   – our company slideshow next to the customer's (the fixed v1.0.37 layout).
 *   custom  – the free-form per-format zone tree (Vollausbau), authored on the canvas.
 * The customer keeps their own zone (the Präsentation field above) and never sees or
 * changes any of this: PHP-gated, so their browser is never even sent this markup.
 * zoneFields() draws only the shell; initZoneEditor() wires it after insertion and
 * hangs _getZoneBody() on the card for the save handler.
 */
function zoneFields(d){
  const mode=d.zone_mode||'single', axis=d.zone_axis||'rows', split=d.zone_split??70;
  const cid=d.company_presentation_id;
  const fmtTabs=[['portrait','Hochkant'],['landscape','Quer'],['phone','Telefon'],['tablet','Tablet']]
    .map(([f,l])=>`<button type="button" class="ghost" data-zfmt="${f}">${l}</button>`).join('');
  return `
    <div class="ze-wrap" data-zone-root>
      <div class="row" style="align-items:center;gap:10px;margin-bottom:8px">
        <b>🗂 Bildschirm-Zonen</b>
        <span class="muted">Eine Fläche · fester Firma/Kunde-Split · oder frei aufgeteilt</span>
      </div>
      <div><label class="f">Aufteilung</label>
        <select data-f="zone_mode" data-zone-mode style="width:100%;max-width:340px">
          <option value="single"${mode==='single'?' selected':''}>Eine Fläche (Kunde)</option>
          <option value="split"${mode==='split'?' selected':''}>Geteilt: Firma + Kunde</option>
          <option value="custom"${mode==='custom'?' selected':''}>Frei aufgeteilt (Zonen-Editor)</option>
        </select></div>

      <div data-zone-legacy style="display:none;margin-top:10px">
        <div class="grid2">
          <div><label class="f">Teilung</label>
            <select data-f="zone_axis" style="width:100%">
              <option value="rows"${axis==='rows'?' selected':''}>Übereinander</option>
              <option value="cols"${axis==='cols'?' selected':''}>Nebeneinander</option>
            </select></div>
          <div><label class="f">Anteil Firmen-Zone (%)</label>
            <input type="number" min="10" max="90" step="5" data-f="zone_split" value="${split}" style="width:100%"></div>
          <div style="grid-column:1/-1"><label class="f">Firmen-Präsentation</label>
            <select data-f="company_presentation_id" style="width:100%"><option value="">—</option>${zoneSourceGroups(cid,false)}</select></div>
        </div>
        <p class="muted" style="margin:8px 0 0">Firmen-Zone zuerst (oben/links), Kunden-Zone füllt den Rest.</p>
      </div>

      <div data-zone-custom style="display:none;margin-top:10px">
        <div class="ze-tabs" data-zfmt-tabs>${fmtTabs}</div>
        <p class="muted" style="margin:0 0 8px">Jede Zone hat eine Quelle. „▤/▥“ teilt sie, „✕“ entfernt sie. Layout je Format getrennt; fehlende Formate laufen als Einzelfläche.</p>
        <div class="ze-canvas-wrap" data-ze-canvas></div>
      </div>
    </div>`;
}

/**
 * Wire the zone controls after the card HTML is inserted (staff only). Builds the
 * per-format zone trees, renders the recursive canvas, and exposes card._getZoneBody()
 * — the single source the save handler reads to send the right PUT for the mode.
 */
function initZoneEditor(card, d){
  const root=card.querySelector('[data-zone-root]'); if(!root) return;
  const modeSel=root.querySelector('[data-zone-mode]');
  const legacy=root.querySelector('[data-zone-legacy]');
  const custom=root.querySelector('[data-zone-custom]');
  const tabsEl=root.querySelector('[data-zfmt-tabs]');
  const canvas=root.querySelector('[data-ze-canvas]');

  // Per-format trees. A missing format defaults to a single customer zone, which
  // resolves identically to the 'single' fallback, so storing it is harmless.
  const FMTS=['portrait','landscape','phone','tablet'];
  let state={};
  try{ const lay=d.zone_layout?(typeof d.zone_layout==='string'?JSON.parse(d.zone_layout):d.zone_layout):null;
       if(lay&&lay.layouts) state=lay.layouts; }catch(e){ state={}; }
  FMTS.forEach(f=>{ if(!state[f]) state[f]={zone:{source:'customer'}}; });
  let fmt=(d.display_format&&FMTS.includes(d.display_format))?d.display_format:'portrait';

  // The tree is a binary structure: splitting a leaf makes two children, deleting
  // one child collapses the split back into its sibling.
  const getNode=path=>{ let n=state[fmt]; for(const i of path) n=n.children[i].node; return n; };
  const setNode=(path,nw)=>{ if(!path.length){ state[fmt]=nw; return; }
    let n=state[fmt]; for(let k=0;k<path.length-1;k++) n=n.children[path[k]].node;
    n.children[path[path.length-1]].node=nw; };
  const splitAt=(path,axis)=>{ const old=getNode(path);
    setNode(path,{axis,children:[{size:50,node:old},{size:50,node:{zone:{source:'customer'}}}]}); draw(); };
  const delAt=path=>{ if(!path.length){ setNode([],{zone:{source:'customer'}}); draw(); return; }
    const pp=path.slice(0,-1), idx=path[path.length-1], parent=getNode(pp);
    parent.children.splice(idx,1);
    if(parent.children.length===1) setNode(pp, parent.children[0].node);
    draw(); };

  const mkBtn=(txt,title,fn)=>{ const b=document.createElement('button'); b.type='button';
    b.className='ghost'; b.textContent=txt; b.title=title; b.onclick=fn; return b; };

  function elFor(path){
    const node=getNode(path);
    if(node&&node.children){
      const box=document.createElement('div'); box.className='ze-split';
      box.style.flexDirection = node.axis==='cols'?'row':'column';
      node.children.forEach((ch,i)=>{
        const w=document.createElement('div'); w.className='ze-child'; w.style.flex=String(ch.size||1);
        w.appendChild(elFor(path.concat(i))); box.appendChild(w);
      });
      const ctl=document.createElement('div'); ctl.className='ze-splitctl';
      ctl.appendChild(mkBtn(node.axis==='cols'?'▥':'▤','Achse wechseln',
        ()=>{ node.axis=node.axis==='cols'?'rows':'cols'; draw(); }));
      if(node.children.length===2){
        const rg=document.createElement('input'); rg.type='range'; rg.min='10'; rg.max='90'; rg.step='5';
        rg.value=String(Math.round(node.children[0].size)); rg.title='Größenverhältnis';
        rg.oninput=()=>{ const p=+rg.value; node.children[0].size=p; node.children[1].size=100-p;
          box.children[0].style.flex=String(p); box.children[1].style.flex=String(100-p); };
        ctl.appendChild(rg);
      }
      box.appendChild(ctl);
      return box;
    }
    const leaf=document.createElement('div'); leaf.className='ze-leaf';
    const sel=document.createElement('select'); sel.className='ze-src';
    sel.innerHTML=zoneSourceGroups(node.zone.source,true);
    sel.onchange=()=>{ const v=sel.value; node.zone.source = v==='customer'?'customer':(+v); };
    const btns=document.createElement('div'); btns.className='ze-btns';
    btns.appendChild(mkBtn('▤','In Zeilen teilen',()=>splitAt(path,'rows')));
    btns.appendChild(mkBtn('▥','In Spalten teilen',()=>splitAt(path,'cols')));
    const x=mkBtn('✕','Zone entfernen',()=>delAt(path)); if(!path.length) x.title='Alles zurücksetzen';
    btns.appendChild(x);
    leaf.append(sel,btns);
    return leaf;
  }

  function draw(){
    canvas.innerHTML='';
    const land=(fmt==='landscape'||fmt==='tablet');
    const c=document.createElement('div'); c.className='ze-canvas '+(land?'landscape':'portrait');
    c.appendChild(elFor([])); canvas.appendChild(c);
    tabsEl.querySelectorAll('button').forEach(b=>{ b.className=(b.dataset.zfmt===fmt)?'sm':'ghost'; });
  }

  tabsEl.querySelectorAll('[data-zfmt]').forEach(b=>{ b.onclick=()=>{ fmt=b.dataset.zfmt; draw(); }; });

  function updateVis(){ const m=modeSel.value;
    legacy.style.display = m==='split'?'':'none';
    custom.style.display = m==='custom'?'':'none';
    if(m==='custom') draw(); }
  modeSel.onchange=updateVis; updateVis();

  card._getZoneBody=()=>{ const m=modeSel.value;
    if(m==='custom') return { zone_mode:'custom', zone_layout:{v:1,layouts:state} };
    const g=f=>root.querySelector(`[data-f="${f}"]`);
    return { zone_mode:m, zone_axis:g('zone_axis').value, zone_split:+g('zone_split').value||70,
             company_presentation_id:g('company_presentation_id').value||null }; };
}
<?php endif; ?>

function renderDetail(t, devices, presentations){
  const body=$('#detailBody');
  body.innerHTML='';

  // Tabs: Präsentationen / Geräte / Einstellungen — only one panel visible at a time.
  const tabs=document.createElement('div'); tabs.className='tabs';
  const panels={};
  const showTab=name=>{
    document.getElementById('slidesEditor')?.remove(); // leave the slides editor when switching tabs
    Object.keys(panels).forEach(k=>{ panels[k].style.display=(k===name)?'':'none'; });
    tabs.querySelectorAll('button').forEach(b=>{ b.className=(b.dataset.tab===name)?'tab active':'tab'; });
  };
  const tabDefs = IS_KUNDE
    ? [['pres','Präsentationen'],['dev','Geräte'],['usr','Zugänge']]   // no tenant-level settings for a customer
    : [['pres','Präsentationen'],['dev','Geräte'],['usr','Zugänge'],['set','Einstellungen']];
  tabDefs.forEach(([k,label])=>{
    const b=document.createElement('button'); b.className='tab'; b.dataset.tab=k; b.textContent=label; b.onclick=()=>showTab(k);
    tabs.appendChild(b);
  });
  body.appendChild(tabs);

  // Presentations
  const pWrap=document.createElement('div'); pWrap.className='card'; pWrap.id='panelPres';
  pWrap.innerHTML=`<h3>Präsentationen</h3>`;
  const activePresIds=new Set(devices.map(d=>String(d.presentation_id)).filter(v=>v&&v!=='null'));
  const hasDevices=devices.length>0;
  presentations.forEach(p=>{
    const active=activePresIds.has(String(p.id));
    const row=document.createElement('div'); row.className='row wrap2'; row.style.marginBottom='8px';
    const eyeTitle=!hasDevices?'Kein Gerät verknüpft'
      :active?'Läuft auf dem Gerät – klicken zum Ausblenden'
      :'Auf dem Gerät anzeigen';
    row.innerHTML=`<span class="grow">${esc(p.name)}${active?' <span class="badge-on">aktiv</span>':''}</span>
      <button class="eye${active?' on':''}" data-eye title="${eyeTitle}"${hasDevices?'':' disabled'}>${eyeSvg(active)}</button>
      <button class="ghost sm" data-ren title="Umbenennen">✎</button>
      <button class="ghost sm" data-edit>Slides</button>
      <button class="ghost sm" data-del>Löschen</button>`;
    row.querySelector('[data-eye]').onclick=async()=>{
      await API.call('presentations.php','PUT',{id:p.id,active:!active});
      toast(!active?'Auf dem Gerät aktiviert':'Ausgeblendet'); selectTenant(t); };
    row.querySelector('[data-ren]').onclick=async()=>{
      const name=await promptInline('Präsentation umbenennen', p.name);
      if(name===null||name===''||name===p.name) return;
      await API.call('presentations.php','PUT',{id:p.id,name}); toast('Umbenannt'); selectTenant(t); };
    row.querySelector('[data-edit]').onclick=()=>editPresentation(p);
    row.querySelector('[data-del]').onclick=async()=>{ if(await confirmDialog('Präsentation löschen?', p.name)){
      await API.call('presentations.php?id='+p.id,'DELETE'); toast('Gelöscht'); selectTenant(t); } };
    pWrap.appendChild(row);
  });
  const pAdd=document.createElement('div'); pAdd.className='row'; pAdd.style.marginTop='10px';
  pAdd.innerHTML=`<input class="grow" id="newPres" placeholder="Neue Präsentation…"><button class="sm">+</button>`;
  pAdd.querySelector('button').onclick=async()=>{ const name=$('#newPres').value.trim(); if(!name)return;
    await API.call('presentations.php','POST',{tenant_id:t.id,name}); toast('Erstellt'); selectTenant(t); };
  pWrap.appendChild(pAdd);
  panels.pres=pWrap;
  body.appendChild(pWrap);

  // Devices
  const dWrap=document.createElement('div'); dWrap.className='card';
  dWrap.innerHTML=`<h3>Geräte</h3>`;
  let pairWrap=null; // eigener Koppel-Kasten, wird oberhalb der Liste platziert (s. u.)
  devices.forEach(d=>{
    const c=document.createElement('div'); c.style.cssText='border-top:1px solid var(--line);padding-top:10px;margin-top:10px';
    const presOpts = presentations.map(p=>`<option value="${p.id}" ${String(p.id)===String(d.presentation_id)?'selected':''}>${esc(p.name)}</option>`).join('');
    // Customers pick what runs on their screen; the screen itself (name, place,
    // format, pairing) is ours to configure, so they get a read-only summary.
    const fields = IS_KUNDE ? `
      <div class="grid2" style="margin-top:8px">
        <div><label class="f">Präsentation</label><select data-f="presentation_id" style="width:100%"><option value="">—</option>${presOpts}</select></div>
        <div><label class="f">Standort</label><div class="muted" style="padding:9px 0">${esc(d.standort)||'—'}</div></div>
      </div>` : `
      <div class="grid2" style="margin-top:8px">
        <div><label class="f">Name</label><input value="${esc(d.name)}" data-f="name" style="width:100%"></div>
        <div><label class="f">Standort</label><input value="${esc(d.standort)}" data-f="standort" style="width:100%"></div>
        <div><label class="f">Projektnummer</label><input value="${esc(d.projektnummer||'')}" data-f="projektnummer" style="width:100%" placeholder="z.B. 2723"></div>
        <div><label class="f">Anzeige-Info</label><input value="${esc(d.anzeige_info)}" data-f="anzeige_info" style="width:100%"></div>
        <div><label class="f">Präsentation</label><select data-f="presentation_id" style="width:100%"><option value="">—</option>${presOpts}</select></div>
        <div><label class="f">Anzeigeformat</label><select data-f="display_format" style="width:100%">${[['portrait','Hochkant-Signage'],['phone','Telefon'],['landscape','Querformat / TV'],['tablet','Tablet']].map(([v,l])=>`<option value="${v}"${(d.display_format||'portrait')===v?' selected':''}>${l}</option>`).join('')}</select></div>
      </div>
      ${zoneFields(d)}`;
    c.innerHTML=`
      <div class="row wrap2">
        <b>${esc(d.name||'(ohne Name)')}</b>
        ${IS_KUNDE?'':`<span class="pair">${esc(d.pairing_code)}</span>`}
        ${statusPill(d)}
        <span class="tag">${d.last_seen?('zuletzt: '+esc(d.last_seen)):'nie gesehen'}</span>
        <span class="spacer" style="flex:1"></span>
        <button class="ghost sm" data-preview title="Vorschau: wie dieser Bildschirm abspielt">🔍 Vorschau</button>
        ${IS_KUNDE?'':'<button class="ghost sm" data-deldev>Löschen</button>'}
      </div>
      ${fields}
      <div class="grid2" style="margin-top:8px">
        <div><label class="f">Wetter-Ort</label><input value="${esc(d._w_loc||'')}" data-w="weather_location" style="width:100%" placeholder="z.B. Berlin,DE"></div>
        <div style="display:flex;align-items:flex-end;padding-bottom:8px"><label class="f" style="margin:0"><input type="checkbox" data-w="weather_enabled" ${d._w_en?'checked':''}> Wetter aktiv</label></div>
      </div>
      <div style="margin-top:12px;border:1px solid var(--line);border-radius:12px;padding:14px;background:rgba(210,26,85,.04)">
        <div class="row" style="align-items:center;gap:10px;margin-bottom:8px">
          <b>🔤 Laufschrift</b>
          <span class="muted">Kasten unten, läuft rechts → links</span>
          <span class="spacer" style="flex:1"></span>
          <label class="f" style="margin:0"><input type="checkbox" data-w="notices_enabled" ${d._n_en?'checked':''}> aktiv</label>
        </div>
        <label class="f">Text</label>
        <textarea data-w="notices_text" style="width:100%" rows="2">${esc(d._n_txt||'')}</textarea>
        <div class="row" style="gap:14px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
          <div><label class="f">Schriftart</label>
            <select data-w="notices_font" style="width:150px">
              <option value="">Standard</option>
              <option value="serif">Serif</option>
              <option value="monospace">Monospace</option>
              <option value="sans-serif-condensed">Schmal</option>
              <option value="sans-serif-light">Leicht</option>
              <option value="sans-serif-medium">Medium</option>
            </select></div>
          <div><label class="f">Schriftgröße (sp)</label><input type="number" min="8" max="80" data-w="notices_size" style="width:90px" value="15"></div>
          <div><label class="f">Schriftfarbe</label><input type="color" data-nc="fg" style="width:56px;height:34px;padding:2px" value="#FFFFFF"></div>
          <div><label class="f">Tempo (dp/s)</label><input type="number" min="20" max="400" step="10" data-w="notices_speed" style="width:90px" value="90"></div>
        </div>
        <div class="row" style="gap:14px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
          <div><label class="f">Rahmen-Höhe (dp, 0=auto)</label><input type="number" min="0" max="300" data-w="notices_height" style="width:120px" value="0"></div>
          <div><label class="f">Rahmen-Farbe</label><input type="color" data-nc="rgb" style="width:56px;height:34px;padding:2px" value="#000000"></div>
          <div><label class="f">Rahmen-Deckkraft (%)</label><input type="number" min="0" max="100" data-nc="op" style="width:90px" value="40"></div>
        </div>
      </div>
      <div class="row" style="margin-top:8px"><span class="spacer" style="flex:1"></span><button class="sm" data-savedev>Änderungen speichern</button></div>`;
    c.querySelector('[data-preview]').onclick=()=>pvDevice(d.pairing_code, d.name||'Gerät');
    if(!IS_KUNDE) initZoneEditor(c, d);
    c.querySelector('[data-savedev]').onclick=async()=>{
      const g=f=>c.querySelector(`[data-f="${f}"]`).value;
      const devBody = IS_KUNDE
        ? {id:d.id, presentation_id:g('presentation_id')||null}
        : {id:d.id,name:g('name'),standort:g('standort'),projektnummer:g('projektnummer'),anzeige_info:g('anzeige_info'),presentation_id:g('presentation_id')||null,display_format:g('display_format'),
           ...(c._getZoneBody?c._getZoneBody():{})};
      await API.call('devices.php','PUT',devBody);
      const w=f=>c.querySelector(`[data-w="${f}"]`);
      const nc=k=>c.querySelector(`[data-nc="${k}"]`);
      const op=Math.max(0,Math.min(100,parseInt(nc('op').value||'0',10)||0));
      const aHex=Math.round(op/100*255).toString(16).padStart(2,'0');
      const nbg='#'+aHex+(nc('rgb').value||'#000000').slice(1);
      await API.call('widgets.php','PUT',{device_id:d.id,
        weather_enabled:w('weather_enabled').checked, weather_location:w('weather_location').value,
        notices_enabled:w('notices_enabled').checked, notices_text:w('notices_text').value,
        notices_size:+w('notices_size').value||15, notices_height:+w('notices_height').value||0, notices_bg:nbg,
        notices_font:w('notices_font').value, notices_speed:+w('notices_speed').value||90,
        notices_color:(nc('fg').value||'#FFFFFF')});
      toast('Gerät gespeichert');
    };
    c.querySelector('[data-deldev]')?.addEventListener('click', async()=>{ if(await confirmDialog('Gerät löschen?', d.name||d.pairing_code)){
      await API.call('devices.php?id='+d.id,'DELETE'); toast('Gelöscht'); selectTenant(t); } });
    dWrap.appendChild(c);
    // fetch widget values lazily
    API.call('widgets.php?device_id='+d.id).then(r=>{ const w=r.widget||{};
      const set=(f,v)=>{ const el=c.querySelector(`[data-w="${f}"]`); if(!el)return; if(el.type==='checkbox') el.checked=!!(+v); else el.value=v??''; };
      set('weather_location',w.weather_location); set('weather_enabled',w.weather_enabled);
      set('notices_enabled',w.notices_enabled); set('notices_text',w.notices_text);
      set('notices_size',w.notices_size??15); set('notices_height',w.notices_height??0);
      set('notices_font',w.notices_font||''); set('notices_speed',w.notices_speed??90);
      const nc=k=>c.querySelector(`[data-nc="${k}"]`);
      // Text colour: strip alpha for the <input type=color>.
      let fg=(w.notices_color||'#FFFFFF').trim();
      if(/^#[0-9a-fA-F]{8}$/.test(fg)) fg='#'+fg.slice(3);
      if(nc('fg')) nc('fg').value=(/^#[0-9a-fA-F]{6}$/.test(fg)?fg:'#FFFFFF');
      // Split stored #AARRGGBB (or #RRGGBB) into colour + opacity% for the border inputs.
      let bg=(w.notices_bg||'#66000000').trim(), a=255, rgb='#000000';
      if(/^#[0-9a-fA-F]{8}$/.test(bg)){ a=parseInt(bg.slice(1,3),16); rgb='#'+bg.slice(3); }
      else if(/^#[0-9a-fA-F]{6}$/.test(bg)){ a=255; rgb=bg; }
      if(nc('rgb')) nc('rgb').value=rgb;
      if(nc('op')) nc('op').value=Math.round(a/255*100);
    }).catch(()=>{});
  });
  // Devices are provisioned by us (devices.php POST is staff-only), so a customer
  // gets no create row — and a hint when we haven't paired a screen for them yet.
  if (IS_KUNDE) {
    if (!devices.length) {
      const hint=document.createElement('p'); hint.className='muted'; hint.style.marginTop='10px';
      hint.textContent='Für Sie ist noch kein Bildschirm eingerichtet. Ihr Ansprechpartner richtet ihn ein.';
      dWrap.appendChild(hint);
    }
  } else {
    // Eigener Kasten zum Koppeln — optisch analog zur "App-Installation"-Kachel,
    // wird oberhalb der Geräteliste platziert (nicht mehr gedrängt darunter).
    pairWrap=document.createElement('div'); pairWrap.className='card';
    pairWrap.style.cssText='border:1px solid var(--magenta);background:rgba(210,26,85,.06);margin-bottom:14px';
    const dAddPres=presentations.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('');
    pairWrap.innerHTML=`<h3 style="display:flex;align-items:center;gap:8px">➕ Neues Gerät koppeln</h3>
      <p class="muted" style="margin:0 0 12px">Code aus dem „Gerät koppeln"-Screen des Geräts eingeben — oder leer lassen, dann wird ein neuer Code erzeugt. Name und Präsentation sind optional.</p>
      <div class="row wrap2">
        <input id="newDevCode" placeholder="Code vom Gerät (optional)…" style="width:210px;text-transform:uppercase">
        <input class="grow" id="newDev" placeholder="Gerätename…">
        <select id="newDevPres" style="width:180px"><option value="">Präsentation…</option>${dAddPres}</select>
        <button class="sm">+ Gerät</button>
      </div>`;
    pairWrap.querySelector('button').onclick=async()=>{
      const name=$('#newDev').value.trim();
      const code=$('#newDevCode').value.trim().toUpperCase();
      const pid=$('#newDevPres').value;
      const body={tenant_id:t.id,name}; if(code) body.pairing_code=code; if(pid) body.presentation_id=pid;
      try{
        const r=await API.call('devices.php','POST',body);
        toast(code?('Gekoppelt · '+r.pairing_code):('Gerät angelegt · Code '+r.pairing_code)); selectTenant(t);
      }catch(e){ toast('Fehler – Code evtl. schon vergeben'); }
    };
  }

  const devPanel=document.createElement('div');
  if (!IS_KUNDE) {
    // "App-Installation" tile: link to the login-gated download page. Installing
    // and pairing a screen is our job, so a customer never needs the APK.
    const aWrap=document.createElement('div'); aWrap.className='card';
    aWrap.style.cssText='border:1px solid var(--magenta);background:rgba(210,26,85,.06)';
    aWrap.innerHTML=`<h3 style="display:flex;align-items:center;gap:8px">📲 App-Installation</h3>
      <p class="muted" style="margin:0 0 12px">Neues Gerät einrichten oder die App manuell aktualisieren – öffnet die Download-Seite (nur für angemeldete Nutzer erreichbar).</p>
      <a href="download.php" target="_blank" rel="noopener"
         style="display:inline-flex;align-items:center;gap:8px;background:var(--magenta);color:#fff;
                padding:11px 18px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px">
         ⬇ Download-Seite öffnen</a>`;
    const topTiles=document.createElement('div'); topTiles.className='top-tiles';
    topTiles.appendChild(aWrap);
    if (pairWrap){ pairWrap.style.marginBottom='0'; topTiles.appendChild(pairWrap); }
    devPanel.appendChild(topTiles);
  } else if (pairWrap) {
    devPanel.appendChild(pairWrap);
  }
  devPanel.appendChild(dWrap);
  panels.dev=devPanel;
  body.appendChild(devPanel);

  // Zugänge tab: the customer logins for THIS tenant. Staff manage them here, and
  // a customer manages their own colleagues here — users.php confines a
  // tenant-bound actor to role 'kunde' inside their own tenant.
  {
    const uWrap=document.createElement('div'); uWrap.className='card';
    uWrap.innerHTML=`<h3>Zugänge</h3>
      <p class="muted" style="margin:-4px 0 12px">${IS_KUNDE
        ? 'Zugänge für Ihr Team. Jeder Zugang sieht genau diesen Bereich und darf Präsentationen, Medien und Laufschrift pflegen — aber keine Geräte oder Mandanten anlegen.'
        : `Kundenlogins für „${esc(t.name)}“. Ein Kunde sieht ausschließlich diesen Mandanten und darf dort Präsentationen, Medien und Laufschrift pflegen — aber keine Geräte, Mandanten oder Benutzer anlegen.`}</p>
      <div id="usrNotice" style="display:none"></div>
      <div id="usrList" class="muted">Wird geladen…</div>
      <div style="border-top:1px solid var(--line);margin-top:14px;padding-top:12px">
        <div class="grid2">
          <div><label class="f">Vorname</label><input id="uFn" style="width:100%" placeholder="Max"></div>
          <div><label class="f">Nachname</label><input id="uLn" style="width:100%" placeholder="Mustermann"></div>
        </div>
        <label class="f">E-Mail (Login)</label><input id="uMail" type="email" style="width:100%" placeholder="kunde@firma.de">
        <label class="f">Temp-Passwort</label>
        <div class="row"><input id="uPw" class="grow" style="font-family:ui-monospace,monospace"><button class="ghost sm" id="uGen">Generieren</button></div>
        <p class="muted" style="margin:6px 0 0">Muss beim ersten Login geändert werden. Jetzt notieren und dem Kunden weitergeben — später ist es nur noch zurücksetzbar, nicht auslesbar.</p>
        <div class="row" style="margin-top:12px"><span class="spacer" style="flex:1"></span><button class="sm" id="uAdd">+ Zugang anlegen</button></div>
      </div>`;
    panels.usr=uWrap;
    body.appendChild(uWrap);
    // Temp-Passwort NICHT vorbefüllen — Feld bleibt beim Betreten der Seite leer und
    // wird erst auf Klick "Generieren" sichtbar. Anlegen erst, wenn eins erzeugt wurde
    // (bewusst, damit es notiert werden kann — später nur noch zurücksetzbar).
    uWrap.querySelector('#uPw').placeholder='Auf „Generieren“ klicken';
    uWrap.querySelector('#uGen').onclick=()=>{ uWrap.querySelector('#uPw').value=genPw(); };
    uWrap.querySelector('#uAdd').onclick=()=>{
      if(!uWrap.querySelector('#uPw').value){ toast('Bitte zuerst ein Temp-Passwort generieren'); return; }
      createTenantUser(t, uWrap);
    };
    loadTenantUsers(t);
  }

  // Settings tab: tenant-level actions (global settings moved to einstellungen.php)
  if (!IS_KUNDE) {
    const sWrap=document.createElement('div'); sWrap.className='card';
    sWrap.innerHTML='<h3>Einstellungen</h3>'
      +'<p class="muted" style="margin:0 0 8px">Globale Hilfe- &amp; Kontaktdaten unter <a href="einstellungen.php" style="color:var(--magenta)">Einstellungen</a>.</p>';

    const tDel=document.createElement('div'); tDel.className='row'; tDel.style.marginTop='6px';
    tDel.innerHTML=`<button class="ghost sm" style="border-color:#5a2230;color:#ff6b8a">Mandant löschen</button>`;
    tDel.querySelector('button').onclick=async()=>{ if(await confirmDialog('Mandant löschen?', t.name+' — inkl. Geräte & Präsentationen')){
      await API.call('tenants.php?id='+t.id,'DELETE'); activeTenant=null; $('#detailTitle').textContent='Bitte einen Mandanten wählen'; $('#detailBody').innerHTML=''; toast('Gelöscht'); loadTenants(); } };
    sWrap.appendChild(tDel);
    panels.set=sWrap;
    body.appendChild(sWrap);
  }

  showTab('pres');
}

// Presentation slide editor (drag order + duration)
async function editPresentation(p){
  const full=(await API.call('presentations.php?id='+p.id)).presentation;
  let slides=(full.slides||[]).map(s=>({media_name:s.media_name,duration_ms:s.duration_ms,kind:s.kind||'media',
    text_title:s.text_title||'', text_body:s.text_body||''}));
  const body=$('#detailBody');
  const card=document.createElement('div'); card.className='card'; card.id='slidesEditor';
  card.innerHTML=`
    <a href="#" id="backPres" style="display:inline-flex;align-items:center;gap:6px;margin-bottom:10px;color:var(--dim);text-decoration:none;font-size:13px">← Zurück zu Präsentationen</a>
    <h3 style="margin-top:0">Slides — ${esc(p.name)}</h3>
    <ul class="list slides" id="slideList"></ul>
    <div class="row wrap2" style="margin-top:8px;gap:8px">
      <select id="mediaPick" class="grow"></select><button class="sm" id="addSlide">+ Slide</button>
      <input type="file" id="slUpload" accept=".jpg,.jpeg,.png,.webp,.mp4" multiple hidden>
      <button class="sm ghost" id="slUploadBtn" title="Bild/Video hochladen und in die Auswahl übernehmen">⬆ Hochladen</button>
      <button class="sm" id="addWeather" title="Wetter-Zwischenbild einfügen">+ 🌤 Wetter</button>
      <button class="sm" id="addNews" title="Nachricht einfügen (Überschrift + Text, ohne Datei)">+ 📰 Nachricht</button>
      ${IS_KUNDE?'':'<button class="sm ghost" id="editWxLayout" title="Wetter-Layout gestalten">🌤 Layout…</button>'}
    </div>
    <div class="row" style="margin-top:12px"><button id="saveSlides">Speichern</button>
      <button class="ghost" id="pvPres" title="Vorschau des gespeicherten Stands">🔍 Vorschau</button>
      <button class="ghost" id="closeSlides">Schließen</button></div>`;
  // Master-detail: hide the presentation list and show only this editor below the
  // tab bar (which stays pinned). Back/Schließen restores the list.
  document.getElementById('slidesEditor')?.remove();
  const presPanel=document.getElementById('panelPres');
  if (presPanel) presPanel.style.display='none';
  if (body.firstElementChild) body.insertBefore(card, body.firstElementChild.nextSibling);
  else body.appendChild(card);
  card.scrollIntoView({ behavior:'smooth', block:'nearest' });
  const mp=card.querySelector('#mediaPick'); mp.innerHTML=media.map(m=>`<option>${esc(m)}</option>`).join('');

  // Upload images/videos straight from the slide editor (same media/ folder as the
  // Medienpool via upload.php); refresh the picker and preselect the new file.
  const slUpload=card.querySelector('#slUpload'), slUploadBtn=card.querySelector('#slUploadBtn');
  slUploadBtn.onclick=()=>slUpload.click();
  slUpload.onchange=async()=>{
    const files=slUpload.files; if(!files||!files.length) return;
    const label=slUploadBtn.textContent; slUploadBtn.textContent='lädt…'; slUploadBtn.disabled=true;
    let last='', fail=0;
    for(const f of files){ const fd=new FormData(); fd.append('file',f);
      try{ const r=await fetch('upload.php',{method:'POST',body:fd}); const j=await r.json();
        if(j.ok && j.saved && j.saved.length){ last=j.saved[j.saved.length-1]; } else fail++;
      }catch(e){ fail++; }
    }
    slUpload.value=''; slUploadBtn.textContent=label; slUploadBtn.disabled=false;
    await loadMedia();
    mp.innerHTML=media.map(m=>`<option>${esc(m)}</option>`).join('');
    if(last){ mp.value=last; toast(fail?('Hochgeladen: '+last+' ('+fail+' fehlgeschlagen)'):('Hochgeladen: '+last+' – jetzt „+ Slide"')); }
    else toast('Upload fehlgeschlagen');
  };

  function render(){
    const ul=card.querySelector('#slideList'); ul.innerHTML='';
    slides.forEach((s,i)=>{
      const li=document.createElement('li'); li.draggable=true;
      // A news slide has no file: it is edited in place, right where it sits in the order.
      if (s.kind==='news') {
        li.style.cssText='flex-direction:column;align-items:stretch;gap:8px';
        li.innerHTML=`<div class="row" style="gap:8px">
            <span class="handle">⠿</span>
            <span class="mname" style="flex:1;display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:6px;background:#3a1522;border:1px solid #d21a55;color:#fda4b8">📰 Nachricht</span>
            <input class="dur" type="number" min="250" step="250" value="${s.duration_ms}"> <span class="tag">ms</span>
            <button class="ghost sm" data-up>↑</button><button class="ghost sm" data-down>↓</button><button class="ghost sm" data-rm>✕</button>
          </div>
          <input data-nt placeholder="Überschrift…" value="${esc(s.text_title)}" style="width:100%">
          <textarea data-nb rows="3" placeholder="Text der Nachricht…" style="width:100%">${esc(s.text_body)}</textarea>`;
        li.querySelector('[data-nt]').oninput=e=>{ s.text_title=e.target.value; };
        li.querySelector('[data-nb]').oninput=e=>{ s.text_body=e.target.value; };
        // Dragging from inside the text fields would fight with selecting text.
        li.querySelectorAll('input,textarea').forEach(el=>{ el.draggable=false;
          el.onmousedown=ev=>ev.stopPropagation(); });
      } else {
        const label = s.kind==='weather'
          ? `<span class="mname" style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:6px;background:#3a1522;border:1px solid #d21a55;color:#fda4b8">🌤 Wetter-Zwischenbild</span>`
          : `${thumbHtml(s.media_name)}<span class="mname">${esc(s.media_name)}</span>`;
        li.innerHTML=`<span class="handle">⠿</span>${label}
          <input class="dur" type="number" min="250" step="250" value="${s.duration_ms}"> <span class="tag">ms</span>
          <button class="ghost sm" data-up>↑</button><button class="ghost sm" data-down>↓</button><button class="ghost sm" data-rm>✕</button>`;
      }
      const thumb=li.querySelector('.thumb'); if(thumb) thumb.onclick=()=>openLightbox(s.media_name);
      li.querySelector('.dur').onchange=e=>{ s.duration_ms=Math.max(250,parseInt(e.target.value)||8000); };
      li.querySelector('[data-up]').onclick=()=>{ if(i>0){ [slides[i-1],slides[i]]=[slides[i],slides[i-1]]; render(); } };
      li.querySelector('[data-down]').onclick=()=>{ if(i<slides.length-1){ [slides[i+1],slides[i]]=[slides[i],slides[i+1]]; render(); } };
      li.querySelector('[data-rm]').onclick=()=>{ slides.splice(i,1); render(); };
      li.ondragstart=e=>{ e.dataTransfer.setData('text/plain',i); li.classList.add('drag'); };
      li.ondragend=()=>li.classList.remove('drag');
      li.ondragover=e=>e.preventDefault();
      li.ondrop=e=>{ e.preventDefault(); const from=+e.dataTransfer.getData('text/plain'); if(from===i)return;
        const [m]=slides.splice(from,1); slides.splice(i,0,m); render(); };
      ul.appendChild(li);
    });
  }
  render();
  card.querySelector('#addSlide').onclick=()=>{ const m=mp.value; if(m){ slides.push({media_name:m,duration_ms:8000,kind:'media'}); render(); } };
  card.querySelector('#addWeather').onclick=()=>{ slides.push({kind:'weather',media_name:'',duration_ms:8000}); render(); };
  card.querySelector('#addNews').onclick=()=>{
    slides.push({kind:'news',media_name:'',duration_ms:10000,text_title:'',text_body:''}); render(); };
  // The weather template is one global design shared by every tenant (weather_layout.php
  // is staff-only), so the customer never gets this entry point.
  card.querySelector('#editWxLayout')?.addEventListener('click', openWeatherLayout);
  card.querySelector('#saveSlides').onclick=async()=>{ await API.call('presentations.php','PUT',{id:p.id,slides}); toast('Slides gespeichert'); };
  card.querySelector('#pvPres').onclick=()=>pvPresentation(p.id, p.name);
  const closeEditor=()=>{ card.remove(); const pp=document.getElementById('panelPres'); if(pp) pp.style.display=''; };
  card.querySelector('#closeSlides').onclick=closeEditor;
  card.querySelector('#backPres').onclick=(e)=>{ e.preventDefault(); closeEditor(); };
}

// ---- Wetter-Layout (global shared template for all weather interstitials) ----
async function openWeatherLayout(){
  let L; try{ L=(await API.call('weather_layout.php')).config||{}; }catch(e){ toast('Layout laden fehlgeschlagen'); return; }
  L.city=L.city||{show:true,h:'center',v:'top',size:34,color:'#FFFFFF'};
  L.forecast=L.forecast||{show:true,h:'center',v:'middle',size:100};
  L.clock=L.clock||{show:true,h:'right',v:'middle',size:150};
  L.texts=L.texts||[]; if(L.background==null)L.background=''; if(L.scrim==null)L.scrim=20;
  let orient='port';

  const hOpts=[['left','links'],['center','mitte'],['right','rechts']];
  const WXROWS=['header','1','2','3','4','5','6','footer'];
  const vOpts=[['header','Header'],['1','Zeile 1'],['2','Zeile 2'],['3','Zeile 3'],['4','Zeile 4'],['5','Zeile 5'],['6','Zeile 6'],['footer','Footer']];
  const vFix=v=>({top:'header',middle:'4',bottom:'footer'}[v]||v); // migrate legacy values
  const sel=(val,opts)=>opts.map(o=>`<option value="${o[0]}" ${o[0]===val?'selected':''}>${o[1]}</option>`).join('');
  const mediaOpts=`<option value="">(kein Hintergrund)</option>`
    +media.map(m=>`<option value="${esc(m)}" ${m===L.background?'selected':''}>${esc(m)}</option>`).join('');

  const bg=document.createElement('div'); bg.className='wx-bg show';
  bg.innerHTML=`<div class="wx-panel">
    <div class="wx-main">
      <h3>🌤 Wetter-Layout</h3>
      <p class="muted" style="margin:2px 0 10px">Gemeinsame Vorlage für alle Wetter-Zwischenbilder. Ort &amp; Vorhersage kommen je Gerät automatisch.</p>
      <label class="f">Hintergrund (aus Medienpool)</label>
      <select id="wxBgSel" style="width:100%">${mediaOpts}</select>
      <label class="f">Abdunklung: <span id="wxScrimV">${L.scrim}</span>%</label>
      <input id="wxScrim" type="range" min="0" max="100" value="${L.scrim}" style="width:100%">
      <div class="wx-sec" data-k="city">
        <div class="hd"><input type="checkbox" data-x="show" ${L.city.show?'checked':''}> Ort-Name</div>
        <div class="ctl">
          <label>Horizontal<select data-x="h">${sel(L.city.h,hOpts)}</select></label>
          <label>Zeile<select data-x="v">${sel(vFix(L.city.v),vOpts)}</select></label>
          <label>Größe (sp)<input type="number" data-x="size" value="${L.city.size}" min="8" max="200"></label>
          <label>Farbe<input type="color" data-x="color" value="${L.city.color||'#FFFFFF'}"></label>
        </div>
      </div>
      <div class="wx-sec" data-k="forecast">
        <div class="hd"><input type="checkbox" data-x="show" ${L.forecast.show?'checked':''}> 3-Tage-Vorhersage</div>
        <div class="ctl">
          <label>Horizontal<select data-x="h">${sel(L.forecast.h,hOpts)}</select></label>
          <label>Zeile<select data-x="v">${sel(vFix(L.forecast.v),vOpts)}</select></label>
          <label>Größe (%)<input type="number" data-x="size" value="${L.forecast.size}" min="20" max="300" step="10"></label>
        </div>
      </div>
      <div class="wx-sec" data-k="clock">
        <div class="hd"><input type="checkbox" data-x="show" ${L.clock.show?'checked':''}> Analoge Uhr</div>
        <div class="ctl">
          <label>Horizontal<select data-x="h">${sel(L.clock.h,hOpts)}</select></label>
          <label>Zeile<select data-x="v">${sel(vFix(L.clock.v),vOpts)}</select></label>
          <label>Größe (dp)<input type="number" data-x="size" value="${L.clock.size}" min="40" max="600" step="10"></label>
        </div>
      </div>
      <div class="wx-sec" data-k="texts">
        <div class="hd">Freier Text <button class="sm" id="wxAddText" style="margin-left:auto">+ Text</button></div>
        <div id="wxTexts"></div>
      </div>
      <div class="row" style="margin-top:16px">
        <button id="wxSave">Speichern</button>
        <button class="ghost" id="wxClose">Schließen</button>
      </div>
    </div>
    <div class="wx-side">
      <div class="row" style="justify-content:space-between;margin-bottom:8px">
        <span class="muted">Vorschau</span>
        <button class="ghost sm" id="wxOrient">Querformat</button>
      </div>
      <div class="wx-prev" id="wxPrev"></div>
    </div>
  </div>`;
  document.body.appendChild(bg);

  function bindSec(k){
    bg.querySelectorAll(`.wx-sec[data-k="${k}"] [data-x]`).forEach(inp=>{
      inp.oninput=()=>{ const x=inp.dataset.x;
        L[k][x] = inp.type==='checkbox'?inp.checked : (inp.type==='number'?(parseInt(inp.value)||0):inp.value);
        preview(); };
    });
  }
  ['city','forecast','clock'].forEach(bindSec);

  function renderTexts(){
    const box=bg.querySelector('#wxTexts'); box.innerHTML='';
    L.texts.forEach((t,i)=>{
      const row=document.createElement('div'); row.className='wx-txtrow';
      row.innerHTML=`<input type="text" placeholder="Text…" value="${esc(t.text||'')}" data-x="text" style="flex:1;min-width:130px">
        <select data-x="h">${sel(t.h||'center',hOpts)}</select>
        <select data-x="v">${sel(vFix(t.v)||'footer',vOpts)}</select>
        <input type="number" data-x="size" value="${t.size||20}" min="8" max="200" style="width:66px" title="Größe (sp)">
        <input type="color" data-x="color" value="${t.color||'#FFFFFF'}">
        <button class="ghost sm" data-rm title="Entfernen">✕</button>`;
      row.querySelectorAll('[data-x]').forEach(inp=>{ inp.oninput=()=>{ const x=inp.dataset.x;
        t[x]= inp.type==='number'?(parseInt(inp.value)||0):inp.value; preview(); }; });
      row.querySelector('[data-rm]').onclick=()=>{ L.texts.splice(i,1); renderTexts(); preview(); };
      box.appendChild(row);
    });
  }
  bg.querySelector('#wxAddText').onclick=()=>{ if(L.texts.length>=5){ toast('Max. 5 Texte'); return; }
    L.texts.push({text:'',h:'center',v:'footer',size:20,color:'#FFFFFF'}); renderTexts(); preview(); };

  bg.querySelector('#wxBgSel').onchange=e=>{ L.background=e.target.value; preview(); };
  const scr=bg.querySelector('#wxScrim'); scr.oninput=()=>{ L.scrim=parseInt(scr.value)||0;
    bg.querySelector('#wxScrimV').textContent=L.scrim; preview(); };
  bg.querySelector('#wxOrient').onclick=()=>{ orient=orient==='port'?'land':'port';
    bg.querySelector('#wxOrient').textContent=orient==='port'?'Querformat':'Hochformat';
    bg.querySelector('#wxPrev').classList.toggle('land',orient==='land'); preview(); };

  function rowMidPct(v){ let i=WXROWS.indexOf(vFix(v)); if(i<0)i=0; return ((i+0.5)/WXROWS.length)*100; }
  function posStyle(h,v){ let s='position:absolute;',tx='0';
    if(h==='left')s+='left:6%;'; else if(h==='right')s+='right:6%;'; else { s+='left:50%;'; tx='-50%'; }
    s+=`top:${rowMidPct(v)}%;`;
    return s+`transform:translate(${tx},-50%);`; }
  function preview(){
    const prev=bg.querySelector('#wxPrev'); const W=prev.clientWidth||260; const f=W/411; // ~dp width of a phone
    let html='';
    if(L.background) html+=`<img class="bg" src="${mediaUrl(L.background)}" alt="">`;
    html+=`<div class="scrim" style="opacity:${(L.scrim||0)/100}"></div>`;
    // Row guides (Header, 1-6, Footer) so placement is obvious.
    for(let i=0;i<WXROWS.length;i++){ const top=(i/WXROWS.length)*100;
      html+=`<div style="position:absolute;left:0;right:0;top:${top}%;height:${100/WXROWS.length}%;border-top:1px solid rgba(255,255,255,.14);box-sizing:border-box"></div>`;
      html+=`<div style="position:absolute;left:3px;top:${top}%;transform:translateY(2px);font-size:8px;color:rgba(255,255,255,.35)">${WXROWS[i]==='header'?'H':WXROWS[i]==='footer'?'F':WXROWS[i]}</div>`; }
    if(L.city.show){ const fs=Math.max(8,(L.city.size||34)*f);
      html+=`<div class="el" style="${posStyle(L.city.h,L.city.v)}font-size:${fs}px;color:${L.city.color||'#fff'};font-weight:700">Ort</div>`; }
    if(L.forecast.show){ const sc=(L.forecast.size||100)/100; const base=Math.max(7,30*f*sc);
      const day=(d,e,t)=>`<div style="text-align:center"><div style="font-weight:700">${d}</div><div>${e}</div><div style="font-weight:700">${t}°</div></div>`;
      html+=`<div class="el" style="${posStyle(L.forecast.h,L.forecast.v)}font-size:${base}px;display:flex;gap:${base*0.6}px">
        ${day('Mo','☀️',26)}${day('Di','⛅',19)}${day('Mi','☁️',27)}</div>`; }
    if(L.clock.show){ const d=Math.max(16,(L.clock.size||150)*f);
      html+=`<div class="el clock" style="${posStyle(L.clock.h,L.clock.v)}width:${d}px;height:${d}px;font-size:${d*0.5}px">🕐</div>`; }
    L.texts.forEach(t=>{ if(!t.text)return; const fs=Math.max(7,(t.size||20)*f);
      html+=`<div class="el" style="${posStyle(t.h||'center',t.v||'bottom')}font-size:${fs}px;color:${t.color||'#fff'}">${esc(t.text)}</div>`; });
    prev.innerHTML=html;
  }

  bg.querySelector('#wxSave').onclick=async()=>{
    try{ const r=await API.call('weather_layout.php','PUT',{config:L}); if(r&&r.config)L=r.config; toast('Layout gespeichert'); }
    catch(e){ toast('Speichern fehlgeschlagen'); } };
  bg.querySelector('#wxClose').onclick=()=>bg.remove();
  bg.onclick=e=>{ if(e.target===bg) bg.remove(); };

  renderTexts(); preview();
}

// Absent in customer mode — tenants are infrastructure.
$('#addTenant')?.addEventListener('click', async()=>{ const name=$('#newTenant').value.trim(); if(!name)return;
  await API.call('tenants.php','POST',{name}); $('#newTenant').value=''; toast('Mandant erstellt'); loadTenants(); });

// ---- Zugänge: Kundenlogins eines Mandanten ----
function genPw(n=10){
  const a='ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  const arr=new Uint32Array(n); crypto.getRandomValues(arr);
  let s=''; for(let i=0;i<n;i++) s+=a[arr[i]%a.length]; return s;
}
function userErr(e){
  const map={ email_taken:'E-Mail bereits vergeben', invalid_email:'E-Mail ungültig',
    temp_password_too_short:'Temp-Passwort zu kurz (min. 8 Zeichen)',
    kunde_requires_tenant:'Ein Kundenzugang braucht einen Mandanten',
    tenant_not_found:'Mandant nicht gefunden', forbidden:'Keine Berechtigung',
    forbidden_assign_role:'Sie können nur Zugänge für Ihren eigenen Bereich anlegen',
    cannot_delete_self:'Das eigene Konto kann nicht gelöscht werden',
    cannot_deactivate_self:'Das eigene Konto kann nicht deaktiviert werden' };
  return map[e.message] || ('Fehler: '+e.message);
}
async function loadTenantUsers(t){
  const box=document.querySelector('#usrList'); if(!box) return;
  let list=[];
  try { list=((await API.call('users.php?tenant_id='+t.id)).users||[]).filter(u=>u.role==='kunde'); }
  catch(e){ box.textContent='Zugänge konnten nicht geladen werden.'; return; }
  if(!list.length){ box.className='muted'; box.textContent='Noch kein Zugang für diesen Mandanten.'; return; }
  box.className=''; box.innerHTML='';
  list.forEach(u=>{
    const name=[u.first_name,u.last_name].filter(Boolean).join(' ')||u.email;
    // Nobody deactivates or deletes the account they are signed in with (the
    // server refuses it too) — otherwise a customer could lock their team out.
    const self = u.id === CURRENT_UID;
    const row=document.createElement('div'); row.className='row wrap2';
    row.style.cssText='border-top:1px solid var(--line);padding-top:10px;margin-top:10px';
    row.innerHTML=`<div class="grow" style="min-width:180px">
        <b>${esc(name)}</b>${self?' <span class="tag">(angemeldet)</span>':''}
        <div class="muted">${esc(u.email)}</div>
      </div>
      <span class="badge-on" style="${u.active?'':'color:var(--dim);border-color:var(--line);background:transparent'}">${u.active?'aktiv':'inaktiv'}</span>
      <button class="ghost sm" data-reset title="Neues Temp-Passwort vergeben">⟳ Passwort</button>
      <button class="ghost sm" data-act ${self?'disabled':''}>${u.active?'Deaktivieren':'Aktivieren'}</button>
      <button class="ghost sm" data-del ${self?'disabled':''} style="border-color:#5a2230;color:#ff6b8a">Löschen</button>`;
    row.querySelector('[data-reset]').onclick=async()=>{
      const pw=genPw();
      try{
        await API.call('users.php','PUT',{id:u.id,action:'reset_password',temp_password:pw});
        toast('Passwort zurückgesetzt');
        showUserNotice('Passwort zurückgesetzt', name, u.email, pw);
      }catch(e){ toast(userErr(e)); }
    };
    row.querySelector('[data-act]').onclick=async()=>{
      try{ await API.call('users.php','PUT',{id:u.id,role:'kunde',tenant_id:t.id,active:u.active?0:1});
        toast(u.active?'Deaktiviert':'Aktiviert'); loadTenantUsers(t); }
      catch(e){ toast(userErr(e)); }
    };
    row.querySelector('[data-del]').onclick=async()=>{
      if(!(await confirmDialog('Zugang löschen?', name+' ('+u.email+')'))) return;
      try{ await API.call('users.php?id='+u.id,'DELETE'); toast('Gelöscht'); loadTenantUsers(t); }
      catch(e){ toast(userErr(e)); }
    };
    box.appendChild(row);
  });
}
/**
 * Confirmation banner above the list. It STAYS until dismissed: the temp password
 * is shown exactly once, so a modal the user clicks away would take the only copy
 * of it with them.
 */
function showUserNotice(title, name, email, pw){
  const box=document.querySelector('#usrNotice'); if(!box) return;
  box.style.cssText='display:block;border:1px solid var(--magenta);background:rgba(210,26,85,.08);'
    +'border-radius:12px;padding:14px;margin-bottom:14px';
  box.innerHTML=`<div class="row" style="align-items:flex-start;gap:10px">
      <div class="grow">
        <b>✅ ${esc(title)}</b>
        <div style="margin-top:6px">${esc(name)} · ${esc(email)}</div>
        <div class="row" style="margin-top:8px;align-items:center;gap:8px">
          <span class="muted">Temp-Passwort:</span>
          <code style="font-family:ui-monospace,monospace;font-size:14px;background:#0f172a;
                border:1px solid var(--line);border-radius:8px;padding:5px 10px">${esc(pw)}</code>
          <button class="ghost sm" data-copy>Kopieren</button>
        </div>
        <p class="muted" style="margin:8px 0 0">Jetzt notieren und weitergeben — das Passwort lässt sich später
          nicht mehr auslesen, nur neu setzen. Beim ersten Login muss es geändert werden.</p>
      </div>
      <button class="ghost sm" data-close title="Hinweis schließen">✕</button>
    </div>`;
  box.querySelector('[data-copy]').onclick=async()=>{
    try{ await navigator.clipboard.writeText(pw); toast('Passwort kopiert'); }
    catch(e){ toast('Kopieren nicht möglich — bitte manuell notieren'); }
  };
  box.querySelector('[data-close]').onclick=()=>{ box.style.display='none'; box.innerHTML=''; };
}

async function createTenantUser(t, wrap){
  const g=id=>wrap.querySelector(id);
  const email=g('#uMail').value.trim(), pw=g('#uPw').value;
  const fn=g('#uFn').value.trim(), ln=g('#uLn').value.trim();
  if(!email){ toast('E-Mail fehlt'); return; }
  try{
    await API.call('users.php','POST',{
      email, role:'kunde', tenant_id:t.id, temp_password:pw,
      first_name:fn, last_name:ln, active:1,
    });
  }catch(e){ toast(userErr(e)); return; }
  g('#uFn').value=''; g('#uLn').value=''; g('#uMail').value=''; g('#uPw').value=genPw();
  toast('Zugang angelegt');
  // Refresh first, so the banner sits directly above the row it is talking about.
  await loadTenantUsers(t);
  showUserNotice('Zugang angelegt', [fn,ln].filter(Boolean).join(' ')||email, email, pw);
}

// ---- Medienpool (shared media folder: upload / preview / delete) ----
let poolItems=[], poolMeta={}, poolTenants=[], poolById={}, tenantStand={}, tenantProj={};
async function loadPool(){
  let items=[]; try { items=(await API.call('playlist.php')).items||[]; } catch(e){}
  let meta={items:[],tenants:[],standorte:[]}; try { meta=await API.call('media_meta.php'); } catch(e){}
  items = await scopeToOwn(items);
  poolItems=items; media=items.map(i=>i.name); // keep the slide-editor picker in sync
  poolMeta={}; (meta.items||[]).forEach(m=>poolMeta[m.filename]={tenant_id:m.tenant_id, tenant_name:m.tenant_name, note:m.note});
  poolTenants=meta.tenants||[];
  poolById={}; poolTenants.forEach(t=>poolById[t.id]=t);
  tenantStand={}; (meta.standorte||[]).forEach(s=>{ (tenantStand[s.tenant_id]=tenantStand[s.tenant_id]||[]).push(s.standort); });
  tenantProj={}; (meta.projekte||[]).forEach(p=>{ (tenantProj[p.tenant_id]=tenantProj[p.tenant_id]||[]).push(p.projektnummer); });
  buildPoolFilters(meta.standorte||[]);
  renderPool();
}
function buildPoolFilters(standorte){
  const tSel=$('#poolTenant'), sSel=$('#poolStand'); const tv=tSel.value, sv=sSel.value;
  tSel.innerHTML = `<option value="">Alle Mandanten</option>`
    + poolTenants.map(t=>`<option value="${t.id}">${esc(t.name)}</option>`).join('')
    + `<option value="none">— Nicht zugeordnet</option>`;
  const uniq=[...new Set((standorte||[]).map(s=>s.standort))];
  sSel.innerHTML = `<option value="">Alle Standorte</option>` + uniq.map(s=>`<option value="${esc(s)}">${esc(s)}</option>`).join('');
  tSel.value=tv; sSel.value=sv;
  tSel.onchange=renderPool; sSel.onchange=renderPool; $('#poolSearch').oninput=renderPool;
}
function poolTenantOf(name){ const m=poolMeta[name]; return (m && m.tenant_id!=null) ? m.tenant_id : null; }
function poolMatch(it){
  const q=$('#poolSearch').value.trim().toLowerCase();
  const tid=poolTenantOf(it.name);
  if(q){
    const t = tid!=null ? poolById[tid] : null;
    const proj = tid!=null ? (tenantProj[tid]||[]).join(' ') : '';
    const hay = (it.name + ' ' + (t ? t.name : '') + ' ' + proj).toLowerCase();
    if(!hay.includes(q)) return false;
  }
  const mSel=$('#poolTenant').value;
  if(mSel==='none'){ if(tid!==null) return false; }
  else if(mSel){ if(String(tid)!==mSel) return false; }
  const sSel=$('#poolStand').value;
  if(sSel){ const st=(tid!=null)?(tenantStand[tid]||[]):[]; if(!st.includes(sSel)) return false; }
  return true;
}
function poolCard(it){
  const v=isVideo(it.name);
  const inner = v ? `<video muted preload="metadata" src="${mediaUrl(it.name)}#t=0.1"></video><span class="play">▶</span>`
                  : `<img loading="lazy" src="${mediaUrl(it.name)}" alt="">`;
  const tid=poolTenantOf(it.name);
  const opts = `<option value="">— nicht zugeordnet</option>`
    + poolTenants.map(t=>`<option value="${t.id}" ${t.id===tid?'selected':''}>${esc(t.name)}</option>`).join('');
  const card=document.createElement('div'); card.className='pcard';
  // A customer's files are theirs by definition; reassigning to another tenant
  // (or to the unassigned company pool) is refused server-side, so no picker.
  card.innerHTML=`<button class="pdel" title="Löschen">✕</button>
    <div class="pthumb">${inner}</div>
    <div class="pmeta"><div class="pn">${esc(it.name)}</div>
      <div class="psub"><span class="pill">${v?'VIDEO':'BILD'}</span><span>${fmtSize(it.size)}</span></div>
      ${IS_KUNDE?'':`<select class="passign">${opts}</select>`}</div>`;
  card.querySelector('.pthumb').onclick=()=>openLightbox(it.name);
  card.querySelector('.pdel').onclick=()=>delMedia(it.name);
  card.querySelector('.passign')?.addEventListener('change', e=>assignTenant(it.name, e.target.value));
  return card;
}
function renderPool(){
  const box=$('#poolGroups'); box.innerHTML='';
  const shown=poolItems.filter(poolMatch);
  $('#poolCount').textContent = `· ${shown.length}/${poolItems.length} Dateien`;
  const groups=[...poolTenants.map(t=>({id:t.id,name:t.name})), {id:null,name:'Nicht zugeordnet'}];
  let any=false;
  groups.forEach(g=>{
    const files=shown.filter(it=>poolTenantOf(it.name)===g.id);
    if(!files.length) return;
    any=true;
    const sec=document.createElement('div'); sec.className='pgroup';
    const h=document.createElement('h3'); h.textContent=`${g.name} · ${files.length}`; sec.appendChild(h);
    const grid=document.createElement('div'); grid.className='poolGrid';
    files.forEach(it=>grid.appendChild(poolCard(it)));
    sec.appendChild(grid); box.appendChild(sec);
  });
  if(!any) box.innerHTML='<div class="muted" style="padding:20px 0">Keine Medien für diese Auswahl.</div>';
}
async function assignTenant(name, val){
  const tid = val===''? null : parseInt(val);
  try{ await API.call('media_meta.php','PUT',{filename:name, tenant_id:tid});
    poolMeta[name]={...(poolMeta[name]||{}), tenant_id:tid}; toast('Zugeordnet'); renderPool(); }
  catch(e){ toast('Fehler: '+e.message); }
}
async function delMedia(name){
  if(!(await confirmDialog('Medium löschen', '„'+name+'“ wirklich aus dem Pool löschen?'))) return;
  const fd=new FormData(); fd.append('name',name);
  try { await fetch('delete.php',{method:'POST',body:fd}); } catch(e){}
  toast('Gelöscht'); loadPool();
}
async function uploadFiles(files){
  if(!files||!files.length) return;
  const st=$('#upStatus'); st.textContent='lädt hoch…';
  let ok=0, fail=0;
  for(const f of files){ const fd=new FormData(); fd.append('file',f);
    try { const r=await fetch('upload.php',{method:'POST',body:fd}); const j=await r.json(); j.ok?ok++:fail++; } catch(e){ fail++; } }
  st.textContent=`${ok} hochgeladen${fail?', '+fail+' fehlgeschlagen':''}`;
  loadPool(); setTimeout(()=>st.textContent='',4000);
}
const drop=$('#drop'), fileInput=$('#fileInput');
$('#pickBtn').onclick=()=>fileInput.click();
fileInput.onchange=()=>{ uploadFiles(fileInput.files); fileInput.value=''; };
['dragover','dragenter'].forEach(e=>drop.addEventListener(e,ev=>{ev.preventDefault();drop.classList.add('over');}));
['dragleave','dragend'].forEach(e=>drop.addEventListener(e,ev=>{ev.preventDefault();drop.classList.remove('over');}));
drop.addEventListener('drop',ev=>{ev.preventDefault();drop.classList.remove('over');uploadFiles(ev.dataTransfer.files);});

(async()=>{
  // Media-pool is its own focused view, reached via the overview's "Medienpool"
  // link (admin.php#media). It is never shown alongside the tenant config.
  if (location.hash === '#media') {
    document.querySelector('.wrap').style.display = 'none';
    $('#media').style.display = '';
    $('#detailTitle') && ($('#detailTitle').textContent = '');
    await loadPool();
    return;
  }
  await loadTenants();
  await loadMedia();
  const deep = DEEP_TENANT ? tenants.find(x => x.id == DEEP_TENANT) : null;
  if (deep) { await selectTenant(deep); return; }
  // A customer has exactly one tenant — open it instead of asking them to pick.
  if (IS_KUNDE) {
    if (tenants.length) await selectTenant(tenants[0]);
    else $('#detailTitle').textContent = 'Für Ihr Konto ist noch kein Bereich freigeschaltet.';
  }
})();
</script>
</body>
</html>
