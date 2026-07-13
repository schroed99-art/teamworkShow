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
  :root { --magenta:#d21a55; --bg:#0f172a; --panel:#1e293b; --panel2:#26344a; --line:#334155; --text:#f1f5f9; --dim:#94a3b8; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  body::after { content:""; position:fixed; right:28px; bottom:22px; width:min(360px,32vw); height:min(360px,32vw);
    background:url('assets/logo_mark.png') no-repeat right bottom; background-size:contain;
    opacity:.05; pointer-events:none; z-index:0; }
  header { display:flex; align-items:center; gap:12px; padding:14px 20px; border-bottom:1px solid var(--line); position:relative; z-index:1; }
  header h1 { font-size:18px; margin:0; }
  header h1 span { color:var(--magenta); }
  header .ver { color:var(--dim); font-size:12px; }
  .spacer { flex:1; }
  a.nav { color:var(--dim); text-decoration:none; font-size:13px; border:1px solid var(--line); border-radius:8px; padding:6px 12px; }
  a.nav:hover { color:var(--text); border-color:var(--magenta); }
  .wrap { padding:20px; position:relative; z-index:1; max-width:1000px; }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:18px; margin-bottom:16px; }
  .card h3 { margin:0 0 4px; font-size:15px; }
  .muted { color:var(--dim); font-size:12px; }
  label.f { display:block; font-size:11px; color:var(--dim); margin:10px 0 4px; }
  input { width:100%; background:#0f172a; border:1px solid var(--line); color:var(--text); border-radius:9px; padding:10px 12px; font-size:13px; }
  input:focus { outline:none; border-color:var(--magenta); }
  .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; }
  @media (max-width:640px){ .grid2 { grid-template-columns:1fr; } }
  .row { display:flex; gap:8px; align-items:center; }
  button { border:0; border-radius:9px; padding:10px 16px; font-size:13px; font-weight:600; cursor:pointer; background:var(--magenta); color:#fff; }
  button:hover { filter:brightness(1.08); }
  .toast { position:fixed; bottom:18px; left:50%; transform:translateX(-50%); background:var(--panel2);
           border:1px solid var(--magenta); color:var(--text); padding:10px 16px; border-radius:10px; display:none; z-index:60; }
  .toast.show { display:block; }
</style>
</head>
<body>
<header>
  <h1>Teamwork<span>Show</span></h1>
  <span class="ver"><?= $version !== '' ? 'v' . htmlspecialchars($version) : '' ?> · Einstellungen</span>
  <span class="spacer"></span>
  <a class="nav" href="overview.php">← Übersicht</a>
  <a class="nav" href="admin.php">Admin</a>
  <a class="nav" href="benutzer.php">Benutzer</a>
  <a class="nav" href="login.php?logout=1">Abmelden</a>
</header>

<div class="wrap">
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

<div class="toast" id="toast"></div>
<script>
  const $ = s => document.querySelector(s);
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
</script>
</body>
</html>
