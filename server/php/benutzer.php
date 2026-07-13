<?php
/**
 * User management UI (admin + koordinator). Betrachter has no access.
 * Talks to users.php via same-origin fetch (session cookie). Role rules are
 * enforced server-side; the UI only hides/disables what the actor may not do.
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
if (is_file($vfile) && preg_match("/'version'\\s*=>\\s*'([^']+)'/", (string) file_get_contents($vfile), $m)) {
    $version = $m[1];
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teamwork Show — Benutzer</title>
<link rel="icon" type="image/png" sizes="64x64" href="assets/favicon.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<style>
  :root { --magenta:#d21a55; --bg:#0f172a; --panel:#1e293b; --panel2:#26344a; --line:#334155; --text:#f1f5f9; --dim:#94a3b8; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  body::after { content:""; position:fixed; right:28px; bottom:22px; width:min(360px,32vw); height:min(360px,32vw);
    background:url('assets/logo_mark.png') no-repeat right bottom; background-size:contain;
    opacity:.05; pointer-events:none; z-index:0; }
  header { display:flex; align-items:center; gap:12px; padding:14px 20px; border-bottom:1px solid var(--line); position:relative; z-index:30; }
  header h1 { font-size:18px; margin:0; }
  header h1 span { color:var(--magenta); }
  header .ver { color:var(--dim); font-size:12px; }
  header .spacer { flex:1; }
  a.nav { color:var(--dim); text-decoration:none; font-size:13px; border:1px solid var(--line); border-radius:8px; padding:6px 12px; }
  a.nav:hover { color:var(--text); border-color:var(--magenta); }
  .wrap { padding:20px; position:relative; z-index:1; }
  .head { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
  .head .title h2 { margin:0; font-size:20px; }
  .head .title p { margin:2px 0 0; color:var(--dim); font-size:13px; }
  .head .spacer { flex:1; }
  .tabs { display:flex; gap:6px; border-bottom:1px solid var(--line); margin-bottom:16px; flex-wrap:wrap; }
  .tab { padding:9px 14px; font-size:13px; color:var(--dim); cursor:pointer; border-bottom:2px solid transparent; }
  .tab.active { color:var(--text); border-bottom-color:var(--magenta); }
  .count { display:inline-block; font-size:12px; color:var(--dim); background:var(--panel2); border:1px solid var(--line);
           border-radius:999px; padding:3px 10px; margin-bottom:12px; }
  button, .btn { border:0; border-radius:9px; padding:9px 14px; font-size:13px; font-weight:600; cursor:pointer; background:var(--magenta); color:#fff; }
  button.ghost, .btn.ghost { background:transparent; border:1px solid var(--line); color:var(--text); }
  button.ghost:hover { border-color:var(--magenta); }
  button.sm { padding:6px 10px; font-size:12px; }
  .ucard { display:flex; align-items:center; gap:14px; background:var(--panel); border:1px solid var(--line); border-radius:12px;
           padding:14px 16px; margin-bottom:10px; }
  .ucard .main { flex:1; min-width:0; }
  .ucard .name { font-size:15px; font-weight:600; }
  .ucard .email { font-size:12px; color:var(--dim); margin:1px 0 8px; }
  .ucard .meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .badge { font-size:11px; font-weight:600; border-radius:999px; padding:3px 10px; }
  .b-admin { background:#3a0f22; color:#ff7aa8; border:1px solid #5e1636; }
  .b-koordinator { background:#10263a; color:#6db3f2; border:1px solid #1c496e; }
  .b-betrachter { background:#20241d; color:#9fd07a; border:1px solid #34502a; }
  .b-aktiv { background:#12331c; color:#5bd07a; border:1px solid #22502f; }
  .b-inaktiv { background:#26344a; color:#94a3b8; border:1px solid #334155; }
  .kuerzel { font-size:12px; color:var(--dim); }
  .acts { display:flex; gap:6px; }
  .icon { width:34px; height:34px; border-radius:8px; border:1px solid var(--line); background:transparent; color:var(--dim);
          font-size:15px; display:flex; align-items:center; justify-content:center; cursor:pointer; }
  .icon:hover { color:var(--text); border-color:var(--magenta); }
  .icon:disabled { opacity:.3; cursor:not-allowed; }
  .icon.danger:hover { border-color:#ff6b8a; color:#ff6b8a; }
  /* modal */
  .modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.7); display:none; align-items:center; justify-content:center; z-index:50; padding:20px; }
  .modal-bg.show { display:flex; }
  .modal { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:22px; width:min(560px,96vw); max-height:92vh; overflow:auto; }
  .modal h3 { margin:0 0 14px; font-size:16px; display:flex; align-items:center; }
  .modal h3 .x { margin-left:auto; cursor:pointer; color:var(--dim); font-size:18px; }
  label.f { display:block; font-size:11px; color:var(--dim); margin:12px 0 5px; }
  input, select, textarea { width:100%; background:#0f172a; border:1px solid var(--line); color:var(--text); border-radius:9px; padding:10px 12px; font-size:13px; }
  input:focus, select:focus, textarea:focus { outline:none; border-color:var(--magenta); }
  input[readonly] { color:var(--dim); }
  .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .hint { font-size:11px; color:var(--dim); margin-top:5px; }
  .genrow { display:flex; gap:8px; }
  .genrow input { font-family:ui-monospace,monospace; }
  .modal .foot { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
  .toast { position:fixed; bottom:18px; left:50%; transform:translateX(-50%); background:var(--panel2); border:1px solid var(--magenta);
           color:var(--text); padding:10px 16px; border-radius:10px; display:none; z-index:60; }
  .toast.show { display:block; }
  .empty { color:var(--dim); padding:30px 0; }
</style>
</head>
<body>
<header>
  <h1>Teamwork<span>Show</span></h1>
  <?php if ($version !== ''): ?><span class="ver">v<?= htmlspecialchars($version) ?></span><?php endif; ?>
  <span class="spacer"></span>
  <a class="nav" href="overview.php">← Übersicht</a>
  <a class="nav" href="einstellungen.php">Einstellungen</a>
  <?php include __DIR__ . '/nav_user.php'; ?>
</header>

<div class="wrap">
  <div class="head">
    <div class="title">
      <h2>Benutzerverwaltung</h2>
      <p>Benutzer anlegen, bearbeiten und Rollen verwalten.</p>
    </div>
    <span class="spacer"></span>
    <button id="btnNew">+ Benutzer anlegen</button>
  </div>

  <div class="tabs" id="tabs"></div>
  <div class="count" id="count">–</div>
  <div id="list"></div>
</div>

<div class="modal-bg" id="modalBg"><div class="modal" id="modalBox"></div></div>
<div class="modal-bg" id="confirmBg">
  <div class="modal" style="width:min(420px,94vw)">
    <h3 id="confTitle">Bestätigen</h3>
    <p id="confText" style="color:var(--dim);margin:0 0 4px"></p>
    <div class="foot"><button class="ghost" id="confCancel">Abbrechen</button><button id="confOk">Löschen</button></div>
  </div>
</div>
<div class="toast" id="toast"></div>

<script>
const ACTOR_ROLE = <?= json_encode($role) ?>;
const $ = s => document.querySelector(s);
const esc = s => (s??'').toString().replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const ROLE_LABEL = { admin:'Administrator', koordinator:'Koordinator', betrachter:'Betrachter' };
const ROLE_ORDER = ['admin','koordinator','betrachter'];
let users = [], tab = 'alle';

const API = {
  async call(url, method='GET', body=null){
    const opt={ method, headers:{} };
    if(body){ opt.headers['Content-Type']='application/json'; opt.body=JSON.stringify(body); }
    const r=await fetch(url,opt);
    if(r.status===401){ location.href='login.php'; throw new Error('unauthorized'); }
    let d={}; try{ d=await r.json(); }catch(e){}
    if(!r.ok) throw new Error(d.error||('HTTP '+r.status));
    return d;
  }
};
function toast(m){ const t=$('#toast'); t.textContent=m; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),2200); }
function genPw(n=10){ const a='ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'; const arr=new Uint32Array(n); crypto.getRandomValues(arr);
  let s=''; for(let i=0;i<n;i++) s+=a[arr[i]%a.length]; return s; }
function canManage(u){ return ACTOR_ROLE==='admin' || u.role!=='admin'; }
function roleOptions(sel){ return ROLE_ORDER
  .filter(r => ACTOR_ROLE==='admin' || r!=='admin')
  .map(r=>`<option value="${r}" ${r===sel?'selected':''}>${ROLE_LABEL[r]}</option>`).join(''); }
function salutationOptions(sel){ return ['','Herr','Frau','Divers']
  .map(s=>`<option value="${esc(s)}" ${s===(sel||'')?'selected':''}>${s===''?'Bitte wählen':s}</option>`).join(''); }

async function load(){ users=(await API.call('users.php')).users||[]; render(); }

function render(){
  const counts={ alle:users.length, admin:0, koordinator:0, betrachter:0 };
  users.forEach(u=>counts[u.role]!==undefined && counts[u.role]++);
  const tabDefs=[['alle','Alle'],['admin','Administratoren'],['koordinator','Koordinatoren'],['betrachter','Betrachter']];
  $('#tabs').innerHTML=tabDefs.map(([k,l])=>`<div class="tab ${k===tab?'active':''}" data-t="${k}">${l}</div>`).join('');
  $('#tabs').querySelectorAll('.tab').forEach(el=>el.onclick=()=>{ tab=el.dataset.t; render(); });
  const shown=users.filter(u=>tab==='alle'||u.role===tab);
  $('#count').textContent=`${shown.length} Benutzer`;
  const list=$('#list');
  if(!shown.length){ list.innerHTML='<div class="empty">Keine Benutzer in dieser Rolle.</div>'; return; }
  list.innerHTML='';
  shown.forEach(u=>{
    const name=[u.first_name,u.last_name].filter(Boolean).join(' ')||u.email;
    const manage=canManage(u);
    const card=document.createElement('div'); card.className='ucard';
    card.innerHTML=`
      <div class="main">
        <div class="name">${esc(name)}</div>
        <div class="email">${esc(u.email)}</div>
        <div class="meta">
          <span class="badge b-${u.role}">${ROLE_LABEL[u.role]}</span>
          ${u.initials?`<span class="kuerzel">Kürzel: ${esc(u.initials)}</span>`:''}
          <span class="badge ${u.active?'b-aktiv':'b-inaktiv'}">${u.active?'Aktiv':'Inaktiv'}</span>
        </div>
      </div>
      <div class="acts">
        <button class="icon" title="Passwort zurücksetzen" data-a="reset" ${manage?'':'disabled'}>⟳</button>
        <button class="icon" title="Bearbeiten" data-a="edit" ${manage?'':'disabled'}>✎</button>
        <button class="icon danger" title="Löschen" data-a="del" ${manage?'':'disabled'}>🗑</button>
      </div>`;
    if(manage){
      card.querySelector('[data-a="reset"]').onclick=()=>openReset(u);
      card.querySelector('[data-a="edit"]').onclick=()=>openEdit(u);
      card.querySelector('[data-a="del"]').onclick=()=>confirmDelete(u);
    }
    list.appendChild(card);
  });
}

function openModal(html){ $('#modalBox').innerHTML=html; $('#modalBg').classList.add('show');
  const x=$('#modalBox .x'); if(x) x.onclick=closeModal; }
function closeModal(){ $('#modalBg').classList.remove('show'); $('#modalBox').innerHTML=''; }
$('#modalBg').onclick=e=>{ if(e.target===$('#modalBg')) closeModal(); };

function stammFields(u){ return `
  <label class="f">Anrede</label>
  <select id="f_sal">${salutationOptions(u?.salutation)}</select>
  <div class="grid2">
    <div><label class="f">Vorname</label><input id="f_fn" value="${esc(u?.first_name||'')}" placeholder="Max"></div>
    <div><label class="f">Nachname</label><input id="f_ln" value="${esc(u?.last_name||'')}" placeholder="Mustermann"></div>
  </div>
  <label class="f">Anzeigename / Kürzel</label>
  <input id="f_ini" value="${esc(u?.initials||'')}" placeholder="z. B. MM" maxlength="12">`;
}

function openCreate(){
  openModal(`
    <h3>Benutzer anlegen <span class="x">✕</span></h3>
    ${stammFields(null)}
    <label class="f">E-Mail-Adresse (Login)</label>
    <input id="f_email" type="email" placeholder="benutzer@firma.de">
    <label class="f">Rolle</label>
    <select id="f_role">${roleOptions('betrachter')}</select>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:12px;margin-top:14px">
      <label class="f" style="margin-top:0">Temp-Passwort</label>
      <div class="genrow"><input id="f_pw" value=""><button type="button" class="ghost" id="f_gen">Generieren</button></div>
      <div class="hint">Wird beim ersten Login zwingend geändert. Notieren/weitergeben.</div>
    </div>
    <label class="f">Notiz</label>
    <textarea id="f_note" rows="2" placeholder="Interne Notizen…"></textarea>
    <label class="f"><input type="checkbox" id="f_active" checked style="width:auto"> Aktiv</label>
    <div class="foot"><button class="ghost" onclick="closeModal()">Abbrechen</button><button id="f_save">Anlegen</button></div>`);
  $('#f_pw').value=genPw();
  $('#f_gen').onclick=()=>$('#f_pw').value=genPw();
  $('#f_save').onclick=saveCreate;
}
async function saveCreate(){
  const body={ salutation:$('#f_sal').value, first_name:$('#f_fn').value.trim(), last_name:$('#f_ln').value.trim(),
    initials:$('#f_ini').value.trim(), email:$('#f_email').value.trim(), role:$('#f_role').value,
    temp_password:$('#f_pw').value, note:$('#f_note').value, active:$('#f_active').checked?1:0 };
  try{ await API.call('users.php','POST',body); toast('Benutzer angelegt'); closeModal(); load(); }
  catch(e){ toast(errMsg(e)); }
}

function openEdit(u){
  openModal(`
    <h3>Benutzer bearbeiten <span class="x">✕</span></h3>
    ${stammFields(u)}
    <label class="f">E-Mail-Adresse (Login)</label>
    <input value="${esc(u.email)}" readonly>
    <div class="hint">Die E-Mail-Adresse (Login) kann nicht geändert werden.</div>
    <label class="f">Rolle</label>
    <select id="f_role">${roleOptions(u.role)}</select>
    <label class="f">Notiz</label>
    <textarea id="f_note" rows="2" placeholder="Interne Notizen…">${esc(u.note||'')}</textarea>
    <label class="f"><input type="checkbox" id="f_active" ${u.active?'checked':''} style="width:auto"> Aktiv <span class="hint" style="margin-left:6px">Inaktive Benutzer können sich nicht anmelden</span></label>
    <div class="foot"><button class="ghost" onclick="closeModal()">Abbrechen</button><button id="f_save">Speichern</button></div>`);
  $('#f_save').onclick=()=>saveEdit(u.id);
}
async function saveEdit(id){
  const body={ id, salutation:$('#f_sal').value, first_name:$('#f_fn').value.trim(), last_name:$('#f_ln').value.trim(),
    initials:$('#f_ini').value.trim(), role:$('#f_role').value, note:$('#f_note').value, active:$('#f_active').checked?1:0 };
  try{ await API.call('users.php','PUT',body); toast('Gespeichert'); closeModal(); load(); }
  catch(e){ toast(errMsg(e)); }
}

function openReset(u){
  const name=[u.first_name,u.last_name].filter(Boolean).join(' ')||u.email;
  openModal(`
    <h3>Passwort zurücksetzen <span class="x">✕</span></h3>
    <p style="color:var(--dim);margin:0 0 6px">Für <b style="color:var(--text)">${esc(name)}</b> (${esc(u.email)}):</p>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:12px">
      <label class="f" style="margin-top:0">Temp-Passwort</label>
      <div class="genrow"><input id="f_pw"><button type="button" class="ghost" id="f_gen">Generieren</button></div>
      <div class="hint">Beim ersten Login zwingend geändert. Notieren/weitergeben.</div>
    </div>
    <div class="foot"><button class="ghost" onclick="closeModal()">Abbrechen</button><button id="f_save">Zurücksetzen</button></div>`);
  $('#f_pw').value=genPw();
  $('#f_gen').onclick=()=>$('#f_pw').value=genPw();
  $('#f_save').onclick=async()=>{
    try{ await API.call('users.php','PUT',{id:u.id,action:'reset_password',temp_password:$('#f_pw').value});
      toast('Passwort zurückgesetzt'); closeModal(); }
    catch(e){ toast(errMsg(e)); }
  };
}

function confirmDelete(u){
  const name=[u.first_name,u.last_name].filter(Boolean).join(' ')||u.email;
  $('#confTitle').textContent='Benutzer löschen';
  $('#confText').innerHTML=`„${esc(name)}“ (${esc(u.email)}) wirklich löschen?`;
  const bg=$('#confirmBg'); bg.classList.add('show');
  const done=()=>{ bg.classList.remove('show'); $('#confOk').onclick=null; $('#confCancel').onclick=null; };
  $('#confCancel').onclick=done;
  bg.onclick=e=>{ if(e.target===bg) done(); };
  $('#confOk').onclick=async()=>{ try{ await API.call('users.php?id='+u.id,'DELETE'); toast('Gelöscht'); }
    catch(e){ toast(errMsg(e)); } done(); load(); };
}

function errMsg(e){
  const map={ email_taken:'E-Mail bereits vergeben', invalid_email:'E-Mail ungültig', temp_password_too_short:'Temp-Passwort zu kurz (min. 8)',
    last_admin:'Der letzte Admin kann nicht entfernt/deaktiviert werden', forbidden:'Keine Berechtigung',
    forbidden_assign_admin:'Nur ein Admin darf die Admin-Rolle vergeben', cannot_delete_self:'Eigenes Konto kann nicht gelöscht werden' };
  return map[e.message]||('Fehler: '+e.message);
}

$('#btnNew').onclick=openCreate;
load();
</script>
</body>
</html>
