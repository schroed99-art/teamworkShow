<?php
/**
 * Global settings page (not tenant/device specific). Currently hosts the
 * Hilfe & Kontakt card that every device pulls via the playlist sync.
 * Login-guarded; only admin/koordinator may open it.
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
<title>Teamwork Show — Einstellungen</title>
<link rel="icon" type="image/png" sizes="64x64" href="assets/favicon.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<style>
  :root { --magenta:#ff006e; --bg:#0f172a; --panel:#1e293b; --panel2:#26344a; --line:#334155; --text:#f1f5f9; --dim:#94a3b8; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  body::after { content:""; position:fixed; right:28px; bottom:22px; width:min(360px,32vw); height:min(360px,32vw);
    background:url('assets/logo_mark.png') no-repeat right bottom; background-size:contain;
    opacity:.05; pointer-events:none; z-index:0; }
  header { display:flex; align-items:center; gap:12px; padding:14px 20px; border-bottom:1px solid var(--line); position:relative; z-index:30; }
  header h1 { font-size:18px; margin:0; }
  header h1 span { color:var(--magenta); }
  header .ver { color:var(--dim); font-size:12px; }
  .spacer { flex:1; }
  a.nav { color:var(--dim); text-decoration:none; font-size:13px; border:1px solid var(--line); border-radius:8px; padding:6px 12px; }
  a.nav:hover { color:var(--text); border-color:var(--magenta); }
  .wrap { padding:20px; position:relative; z-index:1; }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:18px; margin-bottom:16px; }
  .card h3 { margin:0 0 4px; font-size:15px; }
  .muted { color:var(--dim); font-size:12px; }
  label.f { display:block; font-size:11px; color:var(--dim); margin:10px 0 4px; }
  input { width:100%; background:#0f172a; border:1px solid var(--line); color:var(--text); border-radius:9px; padding:10px 12px; font-size:13px; }
  input:focus { outline:none; border-color:var(--magenta); }
  .grid2 { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px 18px; }
  @media (max-width:1200px){ .grid2 { grid-template-columns:repeat(2, minmax(0,1fr)); } }
  @media (max-width:640px){ .grid2 { grid-template-columns:1fr; } }
  .row { display:flex; gap:8px; align-items:center; }
  button { border:0; border-radius:9px; padding:10px 16px; font-size:13px; font-weight:600; cursor:pointer; background:var(--magenta); color:#fff; }
  button:hover { filter:brightness(1.08); }
  .toast { position:fixed; bottom:18px; left:50%; transform:translateX(-50%); background:var(--panel2);
           border:1px solid var(--magenta); color:var(--text); padding:10px 16px; border-radius:10px; display:none; z-index:60; }
  .toast.show { display:block; }
  .tabs { display:flex; gap:6px; border-bottom:1px solid var(--line); margin-bottom:16px; flex-wrap:wrap; }
  .tab { padding:9px 16px; font-size:13px; color:var(--dim); cursor:pointer; border-bottom:2px solid transparent; }
  .tab.active { color:var(--text); border-bottom-color:var(--magenta); }
  .panel { display:none; }
  .panel.active { display:block; }
  .logfilter { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
  .lf { background:var(--panel2); color:var(--dim); border:1px solid var(--line); border-radius:8px; padding:6px 12px; font-size:12px; font-weight:600; cursor:pointer; }
  .lf:hover { color:var(--text); }
  .lf.active { color:#fff; background:var(--magenta); border-color:var(--magenta); }
  #logtable { max-height:60vh; overflow:auto; border:1px solid var(--line); border-radius:10px; }
  .logtbl { width:100%; border-collapse:collapse; font-size:12.5px; }
  .logtbl th { text-align:left; color:var(--dim); font-weight:600; padding:7px 10px; border-bottom:1px solid var(--line); position:sticky; top:0; background:var(--panel); }
  .logtbl td { padding:7px 10px; border-bottom:1px solid #26344a; vertical-align:top; }
  .logtbl tr:last-child td { border-bottom:0; }
  .lt-ts { white-space:nowrap; color:var(--dim); }
  .lt-det { color:var(--dim); }
  .lbadge { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:600; white-space:nowrap; }
  .lb-auth { background:#1e3a5f; color:#7ab8ff; }
  .lb-device { background:#3a2f0f; color:#ffcf6b; }
  .lb-sync { background:#0f3a2f; color:#5bd6a8; }
  .lb-update { background:#2f0f3a; color:#d68bff; }
  .lb-admin { background:#3a0f22; color:#ff7aa8; }
</style>
<?php require_once __DIR__ . '/brand_partials.php'; echo tw_brand_css(); ?>
</head>
<body>
<header>
  <h1>Teamwork<span>Show</span></h1>
  <span class="ver"><?= $version !== '' ? 'v' . htmlspecialchars($version) : '' ?> · Einstellungen</span>
  <?= tw_area_badge(false) ?>
  <span class="spacer"></span>
  <a class="nav" href="overview.php">← Übersicht</a>
  <?php include __DIR__ . '/nav_user.php'; ?>
</header>
<?= tw_brandby() ?>

<div class="wrap">
  <div class="tabs">
    <div class="tab active" data-p="users">Benutzerverwaltung</div>
    <div class="tab" data-p="help">Hilfe &amp; Kontakt</div>
    <?php if ($role === 'admin'): ?><div class="tab" data-p="log">Protokoll</div><?php endif; ?>
  </div>

  <div class="panel active" id="panel-users">
    <div class="card">
      <h3>Benutzerverwaltung</h3>
      <p class="muted" style="margin:0 0 12px">Konten anlegen, Rollen vergeben, Passwörter zurücksetzen oder Nutzer deaktivieren.</p>
      <a href="benutzer.php" style="display:inline-flex;align-items:center;gap:8px;background:var(--magenta);color:#fff;padding:10px 16px;border-radius:9px;text-decoration:none;font-weight:600;font-size:13px">👥 Benutzer verwalten</a>
    </div>
  </div>

  <div class="panel" id="panel-help">
    <div class="card">
      <h3>Hilfe &amp; Kontakt</h3>
      <p class="muted" style="margin:0 0 8px">Gilt <b>global für alle Geräte</b>. Wird im Wartungsmenü der App unter „Hilfe &amp; Kontakt" angezeigt und beim nächsten Sync übernommen.</p>
      <div class="grid2">
        <div><label class="f">Firmenname</label><input data-h="help_company"></div>
        <div><label class="f">Application</label><input data-h="help_app" placeholder="z.B. Teamwork Show"></div>
        <div><label class="f">Version</label><input data-h="help_version" placeholder="leer = App zeigt eigene Version"></div>
        <div><label class="f">Telefon</label><input data-h="help_phone"></div>
        <div><label class="f">Ansprechpartner</label><input data-h="help_contact"></div>
        <div><label class="f">Internetseite</label><input data-h="help_website" placeholder="https://…"></div>
        <div><label class="f">Support · E-Mail</label><input data-h="help_support_mail" type="email"></div>
        <div><label class="f">Support · Telefon</label><input data-h="help_support_phone"></div>
      </div>
      <div class="row" style="margin-top:14px"><span class="spacer"></span><button id="save">Speichern</button></div>
    </div>
  </div>

  <?php if ($role === 'admin'): ?>
  <div class="panel" id="panel-log">
    <div class="card">
      <h3>Protokoll</h3>
      <p class="muted" style="margin:0 0 12px">Anmeldungen, Geräte-Verbindungen, Synchronisierungen und App-Updates. <b>Kundendaten sind anonymisiert</b> (E-Mail maskiert, Mandant nur als Nummer).</p>
      <div class="logfilter" id="logfilter"></div>
      <div id="logtable"><p class="muted">Lädt…</p></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="toast" id="toast"></div>
<script>
  const $ = s => document.querySelector(s);
  document.querySelectorAll('.tab').forEach(t=>t.onclick=()=>{
    document.querySelectorAll('.tab').forEach(x=>x.classList.toggle('active',x===t));
    document.querySelectorAll('.panel').forEach(p=>p.classList.toggle('active',p.id==='panel-'+t.dataset.p));
    if(t.dataset.p==='log' && typeof loadLog==='function') loadLog();
  });
  const inputs = () => document.querySelectorAll('[data-h]');
  function toast(msg){ const t=$('#toast'); t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),2200); }

  fetch('settings.php').then(r=>r.ok?r.json():null).then(d=>{
    if(d) inputs().forEach(el=>{ el.value = d[el.dataset.h] || ''; });
  }).catch(()=>{});

  $('#save').onclick = async () => {
    const payload = {};
    inputs().forEach(el => payload[el.dataset.h] = el.value);
    try {
      const r = await fetch('settings.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
      toast(r.ok ? 'Gespeichert' : 'Speichern fehlgeschlagen');
    } catch(e) { toast('Speichern fehlgeschlagen'); }
  };
<?php if ($role === 'admin'): ?>
  // --- Protokoll ---------------------------------------------------------
  const LOG_CATS = [['','Alle'],['auth','Anmeldungen'],['device','Geräte'],['sync','Sync'],['update','Updates'],['admin','Admin']];
  let logCat = '';
  const escLog = s => (s??'').toString().replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  function fmtTs(s){ const m=/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s||''); return m?`${m[3]}.${m[2]}.${m[1]} ${m[4]}:${m[5]}`:(s||''); }
  async function loadLog(){
    const f=$('#logfilter');
    f.innerHTML=LOG_CATS.map(([k,l])=>`<button class="lf ${k===logCat?'active':''}" data-k="${k}">${l}</button>`).join('');
    f.querySelectorAll('.lf').forEach(b=>b.onclick=()=>{ logCat=b.dataset.k; loadLog(); });
    const t=$('#logtable'); t.innerHTML='<p class="muted">Lädt…</p>';
    let d; try{ const r=await fetch('audit.php?limit=300'+(logCat?'&category='+encodeURIComponent(logCat):'')); d=r.ok?await r.json():null; }catch(e){ d=null; }
    if(!d||!d.rows){ t.innerHTML='<p class="muted">Konnte Protokoll nicht laden.</p>'; return; }
    if(!d.rows.length){ t.innerHTML='<p class="muted">Keine Einträge in dieser Kategorie.</p>'; return; }
    const body=d.rows.map(r=>{
      const who=[r.actor, r.device_code?('Gerät '+r.device_code):'', r.tenant_id?('Mandant #'+r.tenant_id):''].filter(Boolean).map(escLog).join(' · ');
      return `<tr><td class="lt-ts">${escLog(fmtTs(r.ts))}</td><td><span class="lbadge lb-${escLog(r.category)}">${escLog(r.label)}</span></td><td>${who}</td><td class="lt-det">${escLog(r.detail)}</td></tr>`;
    }).join('');
    t.innerHTML=`<table class="logtbl"><thead><tr><th>Zeit</th><th>Ereignis</th><th>Wer / Gerät</th><th>Detail</th></tr></thead><tbody>${body}</tbody></table>`;
  }
<?php endif; ?>
</script>
</body>
</html>
