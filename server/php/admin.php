<?php
/**
 * Multi-tenant admin dashboard (session-guarded).
 * Manages tenants, devices, presentations (drag-order + per-slide duration) and
 * per-device widgets. Talks to the CRUD endpoints via same-origin fetch (session cookie).
 */
require __DIR__ . '/auth.php';
$role = tw_role();
if ($role === null) {
    header('Location: login.php');
    exit;
}
if (!in_array($role, ['admin', 'koordinator'], true)) {
    header('Location: overview.php');
    exit;
}
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
<style>
  :root { --magenta:#e11d48; --bg:#0f172a; --panel:#1e293b; --panel2:#26344a; --line:#334155;
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
  button.eye { background:transparent; border:1px solid var(--line); color:var(--dim);
    padding:4px 8px; border-radius:8px; display:inline-flex; align-items:center; cursor:pointer; }
  button.eye:hover:not(:disabled) { border-color:var(--magenta); color:var(--text); }
  button.eye.on { color:var(--magenta); border-color:var(--magenta); }
  button.eye:disabled { opacity:.4; cursor:not-allowed; }
  .badge-on { font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
    color:var(--magenta); border:1px solid var(--magenta); border-radius:6px; padding:1px 6px; margin-left:6px; }
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
  .tag { font-size:11px; color:var(--dim); }
  .pair { font-family:ui-monospace,monospace; color:var(--magenta); }
  /* branded confirm modal */
  .modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.7); display:none; align-items:center; justify-content:center; z-index:50; }
  .modal-bg.show { display:flex; }
  .modal { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:22px; width:min(400px,92vw); }
  .modal h3 { margin:0 0 8px; }
  .modal p { color:var(--dim); margin:0 0 18px; }
  .modal .row { justify-content:flex-end; }
  .toast { position:fixed; bottom:18px; left:50%; transform:translateX(-50%); background:var(--panel2);
           border:1px solid var(--magenta); color:var(--text); padding:10px 16px; border-radius:10px; display:none; z-index:60; }
  .toast.show { display:block; }
  /* media pool */
  .drop { margin-top:6px; padding:16px; border:2px dashed var(--line); border-radius:10px;
          display:flex; align-items:center; gap:14px; flex-wrap:wrap; transition:border-color .15s, background .15s; }
  .drop.over { border-color:var(--magenta); background:rgba(225,29,72,.10); }
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
</style>
</head>
<body>
<header>
  <h1>Teamwork<span>Show</span></h1>
  <span class="ver"><?= $version !== '' ? 'v' . htmlspecialchars($version) : '' ?> · Admin</span>
  <span class="spacer"></span>
  <a class="logout" href="overview.php">← Übersicht</a>
  <a class="logout" href="benutzer.php">Benutzer</a>
  <a class="logout" href="login.php?logout=1">Abmelden</a>
</header>

<div class="wrap">
  <div class="panel">
    <h2>Mandanten</h2>
    <ul class="list" id="tenantList"></ul>
    <div class="row" style="margin-top:12px">
      <input class="grow" id="newTenant" placeholder="Neuer Mandant…">
      <button class="sm" id="addTenant">+</button>
    </div>
  </div>

  <div class="panel" id="detail">
    <h2 id="detailTitle">Bitte einen Mandanten wählen</h2>
    <div id="detailBody"></div>
  </div>
</div>

<div class="panel" id="media" style="display:none; margin:18px">
  <h2>Medienpool <span class="muted" id="poolCount"></span></h2>
  <p class="muted" style="margin:-6px 0 10px">Von allen Mandanten geteilt. Präsentationen wählen aus diesem Pool.</p>
  <div class="drop" id="drop">
    <button class="sm" id="pickBtn">Dateien wählen</button>
    <input type="file" id="fileInput" accept=".jpg,.jpeg,.png,.webp,.mp4" multiple hidden>
    <span class="muted">Dateien hierher ziehen oder wählen — <b style="color:var(--text)">gleicher Name = austauschen</b>, neuer Name = hinzufügen. (jpg, jpeg, png, webp, mp4)</span>
    <span id="upStatus"></span>
  </div>
  <div class="poolbar">
    <input class="grow" id="poolSearch" placeholder="Bildname, Mandant oder Projektnummer…">
    <select id="poolTenant"></select>
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

async function loadTenants(){
  tenants = (await API.call('tenants.php')).tenants || [];
  const ul=$('#tenantList'); ul.innerHTML='';
  tenants.forEach(t=>{
    const li=document.createElement('li');
    if (activeTenant && t.id===activeTenant.id) li.className='active';
    li.innerHTML = `<span class="name">${esc(t.name)}</span><button class="ghost sm" data-x="1">✎</button>`;
    li.querySelector('.name').onclick=()=>selectTenant(t);
    li.querySelector('[data-x]').onclick=async(e)=>{ e.stopPropagation();
      const name=await promptInline('Mandant umbenennen', t.name); if(name===null)return;
      await API.call('tenants.php','PUT',{id:t.id,name}); toast('Gespeichert'); await loadTenants(); };
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

async function loadMedia(){ try { media = (await API.call('playlist.php')).items.map(i=>i.name); } catch(e){ media=[]; } }

async function selectTenant(t){
  activeTenant=t; await loadTenants();
  $('#detailTitle').textContent = t.name;
  const [devs, pres] = await Promise.all([
    API.call('devices.php?tenant_id='+t.id),
    API.call('presentations.php?tenant_id='+t.id),
  ]);
  renderDetail(t, devs.devices||[], pres.presentations||[]);
}

function renderDetail(t, devices, presentations){
  const body=$('#detailBody');
  body.innerHTML='';

  // Tabs: Präsentationen / Geräte / Einstellungen — only one panel visible at a time.
  const tabs=document.createElement('div'); tabs.className='row'; tabs.style.cssText='gap:6px;margin-bottom:12px';
  const panels={};
  const showTab=name=>{
    Object.keys(panels).forEach(k=>{ panels[k].style.display=(k===name)?'':'none'; });
    tabs.querySelectorAll('button').forEach(b=>{ b.className=(b.dataset.tab===name)?'sm':'ghost sm'; });
  };
  [['pres','Präsentationen'],['dev','Geräte'],['set','Einstellungen']].forEach(([k,label])=>{
    const b=document.createElement('button'); b.dataset.tab=k; b.textContent=label; b.onclick=()=>showTab(k);
    tabs.appendChild(b);
  });
  body.appendChild(tabs);

  // Presentations
  const pWrap=document.createElement('div'); pWrap.className='card';
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
  devices.forEach(d=>{
    const c=document.createElement('div'); c.style.cssText='border-top:1px solid var(--line);padding-top:10px;margin-top:10px';
    const presOpts = presentations.map(p=>`<option value="${p.id}" ${String(p.id)===String(d.presentation_id)?'selected':''}>${esc(p.name)}</option>`).join('');
    c.innerHTML=`
      <div class="row wrap2">
        <b>${esc(d.name||'(ohne Name)')}</b>
        <span class="pair">${esc(d.pairing_code)}</span>
        ${statusPill(d)}
        <span class="tag">${d.last_seen?('zuletzt: '+esc(d.last_seen)):'nie gesehen'}</span>
        <span class="spacer" style="flex:1"></span>
        <button class="ghost sm" data-deldev>Löschen</button>
      </div>
      <div class="grid2" style="margin-top:8px">
        <div><label class="f">Name</label><input value="${esc(d.name)}" data-f="name" style="width:100%"></div>
        <div><label class="f">Standort</label><input value="${esc(d.standort)}" data-f="standort" style="width:100%"></div>
        <div><label class="f">Projektnummer</label><input value="${esc(d.projektnummer||'')}" data-f="projektnummer" style="width:100%" placeholder="z.B. 2723"></div>
        <div><label class="f">Anzeige-Info</label><input value="${esc(d.anzeige_info)}" data-f="anzeige_info" style="width:100%"></div>
        <div><label class="f">Präsentation</label><select data-f="presentation_id" style="width:100%"><option value="">—</option>${presOpts}</select></div>
      </div>
      <div class="grid2" style="margin-top:8px">
        <div><label class="f">Wetter-Ort</label><input value="${esc(d._w_loc||'')}" data-w="weather_location" style="width:100%" placeholder="z.B. Berlin,DE"></div>
        <div><label class="f"><input type="checkbox" data-w="weather_enabled" ${d._w_en?'checked':''}> Wetter aktiv</label>
             <label class="f"><input type="checkbox" data-w="notices_enabled" ${d._n_en?'checked':''}> Hinweis aktiv</label></div>
        <div style="grid-column:1/3"><label class="f">Hinweis-Text</label><textarea data-w="notices_text" style="width:100%" rows="2">${esc(d._n_txt||'')}</textarea></div>
        <div style="grid-column:1/3"><label class="f">Hinweis-Laufschrift (Kasten unten, rechts→links)</label>
          <div class="row" style="gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div><label class="f">Schriftgröße (sp)</label><input type="number" min="8" max="80" data-w="notices_size" style="width:90px" value="15"></div>
            <div><label class="f">Rahmen-Höhe (dp, 0=auto)</label><input type="number" min="0" max="300" data-w="notices_height" style="width:110px" value="0"></div>
            <div><label class="f">Rahmen-Farbe</label><input type="color" data-nc="rgb" style="width:56px;height:34px;padding:2px" value="#000000"></div>
            <div><label class="f">Deckkraft (%)</label><input type="number" min="0" max="100" data-nc="op" style="width:80px" value="40"></div>
          </div>
        </div>
      </div>
      <div class="row" style="margin-top:8px"><span class="spacer" style="flex:1"></span><button class="sm" data-savedev>Änderungen speichern</button></div>`;
    c.querySelector('[data-savedev]').onclick=async()=>{
      const g=f=>c.querySelector(`[data-f="${f}"]`).value;
      await API.call('devices.php','PUT',{id:d.id,name:g('name'),standort:g('standort'),projektnummer:g('projektnummer'),anzeige_info:g('anzeige_info'),presentation_id:g('presentation_id')||null});
      const w=f=>c.querySelector(`[data-w="${f}"]`);
      const nc=k=>c.querySelector(`[data-nc="${k}"]`);
      const op=Math.max(0,Math.min(100,parseInt(nc('op').value||'0',10)||0));
      const aHex=Math.round(op/100*255).toString(16).padStart(2,'0');
      const nbg='#'+aHex+(nc('rgb').value||'#000000').slice(1);
      await API.call('widgets.php','PUT',{device_id:d.id,
        weather_enabled:w('weather_enabled').checked, weather_location:w('weather_location').value,
        notices_enabled:w('notices_enabled').checked, notices_text:w('notices_text').value,
        notices_size:+w('notices_size').value||15, notices_height:+w('notices_height').value||0, notices_bg:nbg});
      toast('Gerät gespeichert');
    };
    c.querySelector('[data-deldev]').onclick=async()=>{ if(await confirmDialog('Gerät löschen?', d.name||d.pairing_code)){
      await API.call('devices.php?id='+d.id,'DELETE'); toast('Gelöscht'); selectTenant(t); } };
    dWrap.appendChild(c);
    // fetch widget values lazily
    API.call('widgets.php?device_id='+d.id).then(r=>{ const w=r.widget||{};
      const set=(f,v)=>{ const el=c.querySelector(`[data-w="${f}"]`); if(!el)return; if(el.type==='checkbox') el.checked=!!(+v); else el.value=v??''; };
      set('weather_location',w.weather_location); set('weather_enabled',w.weather_enabled);
      set('notices_enabled',w.notices_enabled); set('notices_text',w.notices_text);
      set('notices_size',w.notices_size??15); set('notices_height',w.notices_height??0);
      // Split stored #AARRGGBB (or #RRGGBB) into colour + opacity% for the two inputs.
      const nc=k=>c.querySelector(`[data-nc="${k}"]`);
      let bg=(w.notices_bg||'#66000000').trim(), a=255, rgb='#000000';
      if(/^#[0-9a-fA-F]{8}$/.test(bg)){ a=parseInt(bg.slice(1,3),16); rgb='#'+bg.slice(3); }
      else if(/^#[0-9a-fA-F]{6}$/.test(bg)){ a=255; rgb=bg; }
      if(nc('rgb')) nc('rgb').value=rgb;
      if(nc('op')) nc('op').value=Math.round(a/255*100);
    }).catch(()=>{});
  });
  const dAdd=document.createElement('div'); dAdd.className='row wrap2'; dAdd.style.marginTop='12px';
  const dAddPres=presentations.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('');
  dAdd.innerHTML=`<input id="newDevCode" placeholder="Code vom Gerät (optional)…" style="width:190px;text-transform:uppercase">`+
    `<input class="grow" id="newDev" placeholder="Gerätename…">`+
    `<select id="newDevPres" style="width:170px"><option value="">Präsentation…</option>${dAddPres}</select>`+
    `<button class="sm">+ Gerät</button>`;
  dAdd.querySelector('button').onclick=async()=>{
    const name=$('#newDev').value.trim();
    const code=$('#newDevCode').value.trim().toUpperCase();
    const pid=$('#newDevPres').value;
    const body={tenant_id:t.id,name}; if(code) body.pairing_code=code; if(pid) body.presentation_id=pid;
    try{
      const r=await API.call('devices.php','POST',body);
      toast(code?('Gekoppelt · '+r.pairing_code):('Gerät angelegt · Code '+r.pairing_code)); selectTenant(t);
    }catch(e){ toast('Fehler – Code evtl. schon vergeben'); }
  };
  dWrap.appendChild(dAdd);
  panels.dev=dWrap;
  body.appendChild(dWrap);

  // Settings tab: tenant-level actions
  const sWrap=document.createElement('div'); sWrap.className='card';
  sWrap.innerHTML='<h3>Einstellungen</h3>';
  const tDel=document.createElement('div'); tDel.className='row'; tDel.style.marginTop='6px';
  tDel.innerHTML=`<button class="ghost sm" style="border-color:#5a2230;color:#ff6b8a">Mandant löschen</button>`;
  tDel.querySelector('button').onclick=async()=>{ if(await confirmDialog('Mandant löschen?', t.name+' — inkl. Geräte & Präsentationen')){
    await API.call('tenants.php?id='+t.id,'DELETE'); activeTenant=null; $('#detailTitle').textContent='Bitte einen Mandanten wählen'; $('#detailBody').innerHTML=''; toast('Gelöscht'); loadTenants(); } };
  sWrap.appendChild(tDel);
  panels.set=sWrap;
  body.appendChild(sWrap);

  showTab('pres');
}

// Presentation slide editor (drag order + duration)
async function editPresentation(p){
  const full=(await API.call('presentations.php?id='+p.id)).presentation;
  let slides=(full.slides||[]).map(s=>({media_name:s.media_name,duration_ms:s.duration_ms,kind:s.kind||'media'}));
  const body=$('#detailBody');
  const card=document.createElement('div'); card.className='card';
  card.innerHTML=`<h3>Slides — ${esc(p.name)}</h3>
    <ul class="list slides" id="slideList"></ul>
    <div class="row" style="margin-top:8px">
      <select id="mediaPick" class="grow"></select><button class="sm" id="addSlide">+ Slide</button>
      <button class="sm" id="addWeather" title="Wetter-Zwischenbild einfügen">+ 🌤 Wetter</button>
      <button class="sm ghost" id="editWxLayout" title="Wetter-Layout gestalten">🌤 Layout…</button>
    </div>
    <div class="row" style="margin-top:12px"><button id="saveSlides">Reihenfolge speichern</button>
      <button class="ghost" id="closeSlides">Schließen</button></div>`;
  body.prepend(card);
  const mp=card.querySelector('#mediaPick'); mp.innerHTML=media.map(m=>`<option>${esc(m)}</option>`).join('');

  function render(){
    const ul=card.querySelector('#slideList'); ul.innerHTML='';
    slides.forEach((s,i)=>{
      const li=document.createElement('li'); li.draggable=true;
      const label = s.kind==='weather'
        ? `<span class="mname" style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:6px;background:#3a1522;border:1px solid #e11d48;color:#fda4b8">🌤 Wetter-Zwischenbild</span>`
        : `${thumbHtml(s.media_name)}<span class="mname">${esc(s.media_name)}</span>`;
      li.innerHTML=`<span class="handle">⠿</span>${label}
        <input class="dur" type="number" min="250" step="250" value="${s.duration_ms}"> <span class="tag">ms</span>
        <button class="ghost sm" data-up>↑</button><button class="ghost sm" data-down>↓</button><button class="ghost sm" data-rm>✕</button>`;
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
  card.querySelector('#editWxLayout').onclick=openWeatherLayout;
  card.querySelector('#saveSlides').onclick=async()=>{ await API.call('presentations.php','PUT',{id:p.id,slides}); toast('Slides gespeichert'); };
  card.querySelector('#closeSlides').onclick=()=>card.remove();
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

$('#addTenant').onclick=async()=>{ const name=$('#newTenant').value.trim(); if(!name)return;
  await API.call('tenants.php','POST',{name}); $('#newTenant').value=''; toast('Mandant erstellt'); loadTenants(); };

// ---- Medienpool (shared media folder: upload / preview / delete) ----
let poolItems=[], poolMeta={}, poolTenants=[], poolById={}, tenantStand={}, tenantProj={};
async function loadPool(){
  let items=[]; try { items=(await API.call('playlist.php')).items||[]; } catch(e){}
  let meta={items:[],tenants:[],standorte:[]}; try { meta=await API.call('media_meta.php'); } catch(e){}
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
  card.innerHTML=`<button class="pdel" title="Löschen">✕</button>
    <div class="pthumb">${inner}</div>
    <div class="pmeta"><div class="pn">${esc(it.name)}</div>
      <div class="psub"><span class="pill">${v?'VIDEO':'BILD'}</span><span>${fmtSize(it.size)}</span></div>
      <select class="passign">${opts}</select></div>`;
  card.querySelector('.pthumb').onclick=()=>openLightbox(it.name);
  card.querySelector('.pdel').onclick=()=>delMedia(it.name);
  card.querySelector('.passign').onchange=e=>assignTenant(it.name, e.target.value);
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
  if (DEEP_TENANT) {
    const t = tenants.find(x => x.id == DEEP_TENANT);
    if (t) await selectTenant(t);
  }
})();
</script>
</body>
</html>
