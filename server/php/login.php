<?php
/**
 * Dashboard-admin login (simple session).
 *   GET               -> branded login form
 *   POST password=..  -> on success sets $_SESSION['tw_admin'] and 302 -> admin.php
 *   GET ?logout=1     -> destroys the session, 302 -> login.php
 */
require __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = (string) ($_POST['password'] ?? '');
    $adminPass = (string) (tw_config()['admin_password'] ?? '');
    if ($adminPass !== '' && $adminPass !== 'CHANGE_ME' && hash_equals($adminPass, $pass)) {
        session_regenerate_id(true);
        $_SESSION['tw_admin'] = true;
        header('Location: admin.php');
        exit;
    }
    http_response_code(401);
    $error = 'Falsches Passwort.';
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teamwork Show — Admin Login</title>
<style>
  :root { --magenta:#d81b60; --bg:#000; --panel:#141414; --text:#f4f4f4; --dim:#9a9a9a; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  .card { background:var(--panel); border:1px solid #242424; border-radius:16px; padding:32px; width:min(360px,92vw);
          box-shadow:0 12px 40px rgba(0,0,0,.5); }
  h1 { margin:0 0 4px; font-size:22px; }
  h1 span { color:var(--magenta); }
  p.sub { margin:0 0 24px; color:var(--dim); font-size:13px; }
  label { display:block; font-size:12px; color:var(--dim); margin-bottom:6px; }
  input { width:100%; padding:12px 14px; border-radius:10px; border:1px solid #333; background:#0d0d0d; color:var(--text);
          font-size:15px; }
  input:focus { outline:none; border-color:var(--magenta); }
  button { margin-top:18px; width:100%; padding:12px; border:0; border-radius:10px; background:var(--magenta);
           color:#fff; font-size:15px; font-weight:600; cursor:pointer; }
  button:hover { filter:brightness(1.08); }
  .err { margin-top:14px; color:#ff6b8a; font-size:13px; text-align:center; }
</style>
</head>
<body>
  <form class="card" method="post" action="login.php">
    <h1>Teamwork<span>Show</span></h1>
    <p class="sub">Admin-Bereich · Anmeldung</p>
    <label for="password">Passwort</label>
    <input id="password" name="password" type="password" autofocus autocomplete="current-password">
    <button type="submit">Anmelden</button>
    <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  </form>
</body>
</html>
