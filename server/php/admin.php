<?php
/**
 * Multi-tenant admin dashboard (session-guarded).
 * Manages tenants, devices, presentations (drag-order + per-slide duration) and
 * per-device widgets. Talks to the CRUD endpoints via same-origin fetch (session cookie).
 */
require __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['tw_admin'])) {
    header('Location: login.php');
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
  :root { --magenta:#d81b60; --bg:#0a0a0a; --panel:#151515; --panel2:#1d1d1d; --line:#2a2a2a;
          --text:#f2f2f2; --dim:#9a9a9a; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text);
         font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; font-size:14px; }
  header { display:flex; align-items:center; gap:12px; padding:14px 20px; border-bottom:1px solid var(--line);
           position:sticky; top:0; background:var(--bg); z-index:5; }
  header h1 { font-size:18px; margin:0; }
  header h1 span { color:var(--magenta); }
  header .ver { color:var(--dim); font-size:12px; }
  header .spacer { flex:1; }
  a.logout { color:var(--dim); text-decoration:none; font-size:13px; border:1px solid var(--line);
             padding:6px 12px; border-radius:8px; }
  a.logout:hover { color:var(--text); border-color:var(--magenta); }
  .wrap { display:grid; grid-template-columns:260px 1fr; gap:18px; padding:18px; align-items:start; }
  .panel { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:16px; }
  .panel h2 { margin:0 0 12px; font-size:14px; text-transform:uppercase; letter-spacing:.06em; color:var(--dim); }
  ul.list { list-style:none; margin:0; padding:0; }
  ul.list li { display:flex; align-items:center; gap:8px; padding:9px 10px; border-radius:9px; cursor:pointer; }
  ul.list li:hover { background:var(--panel2); }
  ul.list li.active { background:var(--magenta); color:#fff; }
  ul.list li .name { flex:1; }
  .row { display:flex; gap:8px; align-items:center; }
  .row.wrap2 { flex-wrap:wrap; }
  input, select, textarea { background:#0d0d0d; border:1px solid #333; color:var(--text); border-radius:9px;
                            padding:9px 11px; font-size:13px; }
  input:focus, select:focus, textarea:focus { outline:none; border-color:var(--magenta); }
  input.grow { flex:1; }
  button { border:0; border-radius:9px; padding:9px 13px; font-size:13px; font-weight:600; cursor:pointer;
           background:var(--magenta); color:#fff; }
  button.ghost { background:transparent; border:1px solid var(--line); color:var(--text); }
  button.ghost:hover { border-color:var(--magenta); }
  button.sm { padding:5px 9px; font-size:12px; }
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
</style>
</head>
<body>
<header>
  <h1>Teamwork<span>Show</span></h1>
  <span class="ver"><?= $version !== '' ? 'v' . htmlspecialchars($version) : '' ?> · Admin</span>
  <span class="spacer"></span>
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

  // Presentations
  const pWrap=document.createElement('div'); pWrap.className='card';
  pWrap.innerHTML=`<h3>Präsentationen</h3>`;
  presentations.forEach(p=>{
    const row=document.createElement('div'); row.className='row wrap2'; row.style.marginBottom='8px';
    row.innerHTML=`<span class="grow">${esc(p.name)}</span>
      <button class="ghost sm" data-edit>Slides</button>
      <button class="ghost sm" data-del>Löschen</button>`;
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
        <span class="tag">${d.last_seen?('zuletzt: '+esc(d.last_seen)):'nie gesehen'}</span>
        <span class="spacer" style="flex:1"></span>
        <button class="ghost sm" data-deldev>Löschen</button>
      </div>
      <div class="grid2" style="margin-top:8px">
        <div><label class="f">Name</label><input value="${esc(d.name)}" data-f="name" style="width:100%"></div>
        <div><label class="f">Standort</label><input value="${esc(d.standort)}" data-f="standort" style="width:100%"></div>
        <div><label class="f">Anzeige-Info</label><input value="${esc(d.anzeige_info)}" data-f="anzeige_info" style="width:100%"></div>
        <div><label class="f">Präsentation</label><select data-f="presentation_id" style="width:100%"><option value="">—</option>${presOpts}</select></div>
      </div>
      <div class="grid2" style="margin-top:8px">
        <div><label class="f">Wetter-Ort</label><input value="${esc(d._w_loc||'')}" data-w="weather_location" style="width:100%" placeholder="z.B. Berlin,DE"></div>
        <div><label class="f"><input type="checkbox" data-w="weather_enabled" ${d._w_en?'checked':''}> Wetter aktiv</label>
             <label class="f"><input type="checkbox" data-w="notices_enabled" ${d._n_en?'checked':''}> Hinweis aktiv</label></div>
        <div style="grid-column:1/3"><label class="f">Hinweis-Text</label><textarea data-w="notices_text" style="width:100%" rows="2">${esc(d._n_txt||'')}</textarea></div>
      </div>
      <div class="row" style="margin-top:8px"><button class="sm" data-savedev>Gerät speichern</button></div>`;
    c.querySelector('[data-savedev]').onclick=async()=>{
      const g=f=>c.querySelector(`[data-f="${f}"]`).value;
      await API.call('devices.php','PUT',{id:d.id,name:g('name'),standort:g('standort'),anzeige_info:g('anzeige_info'),presentation_id:g('presentation_id')||null});
      const w=f=>c.querySelector(`[data-w="${f}"]`);
      await API.call('widgets.php','PUT',{device_id:d.id,
        weather_enabled:w('weather_enabled').checked, weather_location:w('weather_location').value,
        notices_enabled:w('notices_enabled').checked, notices_text:w('notices_text').value});
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
    }).catch(()=>{});
  });
  const dAdd=document.createElement('div'); dAdd.className='row'; dAdd.style.marginTop='12px';
  dAdd.innerHTML=`<input class="grow" id="newDev" placeholder="Neues Gerät (Name)…"><button class="sm">+ Gerät</button>`;
  dAdd.querySelector('button').onclick=async()=>{ const name=$('#newDev').value.trim();
    const r=await API.call('devices.php','POST',{tenant_id:t.id,name}); toast('Gerät angelegt · Code '+r.pairing_code); selectTenant(t); };
  dWrap.appendChild(dAdd);
  body.appendChild(dWrap);

  // Tenant delete
  const tDel=document.createElement('div'); tDel.className='row'; tDel.style.marginTop='6px';
  tDel.innerHTML=`<span class="spacer" style="flex:1"></span><button class="ghost sm" style="border-color:#5a2230;color:#ff6b8a">Mandant löschen</button>`;
  tDel.querySelector('button').onclick=async()=>{ if(await confirmDialog('Mandant löschen?', t.name+' — inkl. Geräte & Präsentationen')){
    await API.call('tenants.php?id='+t.id,'DELETE'); activeTenant=null; $('#detailTitle').textContent='Bitte einen Mandanten wählen'; $('#detailBody').innerHTML=''; toast('Gelöscht'); loadTenants(); } };
  body.appendChild(tDel);
}

// Presentation slide editor (drag order + duration)
async function editPresentation(p){
  const full=(await API.call('presentations.php?id='+p.id)).presentation;
  let slides=(full.slides||[]).map(s=>({media_name:s.media_name,duration_ms:s.duration_ms}));
  const body=$('#detailBody');
  const card=document.createElement('div'); card.className='card';
  card.innerHTML=`<h3>Slides — ${esc(p.name)}</h3>
    <ul class="list slides" id="slideList"></ul>
    <div class="row" style="margin-top:8px">
      <select id="mediaPick" class="grow"></select><button class="sm" id="addSlide">+ Slide</button>
    </div>
    <div class="row" style="margin-top:12px"><button id="saveSlides">Reihenfolge speichern</button>
      <button class="ghost" id="closeSlides">Schließen</button></div>`;
  body.prepend(card);
  const mp=card.querySelector('#mediaPick'); mp.innerHTML=media.map(m=>`<option>${esc(m)}</option>`).join('');

  function render(){
    const ul=card.querySelector('#slideList'); ul.innerHTML='';
    slides.forEach((s,i)=>{
      const li=document.createElement('li'); li.draggable=true;
      li.innerHTML=`<span class="handle">⠿</span><span class="mname">${esc(s.media_name)}</span>
        <input class="dur" type="number" min="250" step="250" value="${s.duration_ms}"> <span class="tag">ms</span>
        <button class="ghost sm" data-up>↑</button><button class="ghost sm" data-down>↓</button><button class="ghost sm" data-rm>✕</button>`;
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
  card.querySelector('#addSlide').onclick=()=>{ const m=mp.value; if(m){ slides.push({media_name:m,duration_ms:8000}); render(); } };
  card.querySelector('#saveSlides').onclick=async()=>{ await API.call('presentations.php','PUT',{id:p.id,slides}); toast('Slides gespeichert'); };
  card.querySelector('#closeSlides').onclick=()=>card.remove();
}

$('#addTenant').onclick=async()=>{ const name=$('#newTenant').value.trim(); if(!name)return;
  await API.call('tenants.php','POST',{name}); $('#newTenant').value=''; toast('Mandant erstellt'); loadTenants(); };

(async()=>{ await loadMedia(); await loadTenants(); })();
</script>
</body>
</html>
