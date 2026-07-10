<?php
/**
 * Set a new password for the logged-in user. Shown automatically after a
 * temp-password login (must_change_pw) and reachable voluntarily for a
 * self-service change. Clears must_change_pw on success.
 */
require __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$uid = isset($_SESSION['tw_user_id']) ? (int) $_SESSION['tw_user_id'] : 0;
if ($uid <= 0) {
    header('Location: login.php');
    exit;
}

$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string) ($_POST['password'] ?? '');
    $p2 = (string) ($_POST['password2'] ?? '');
    if (strlen($p1) < 8) {
        $error = 'Das Passwort muss mindestens 8 Zeichen haben.';
    } elseif ($p1 !== $p2) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        $hash = password_hash($p1, PASSWORD_DEFAULT);
        tw_db()->prepare('UPDATE users SET pass_hash = ?, must_change_pw = 0 WHERE id = ?')->execute([$hash, $uid]);
        $done = true;
    }
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teamwork Show — Passwort ändern</title>
<style>
  :root { --magenta:#d81b60; --bg:#000; --panel:#141414; --text:#f4f4f4; --dim:#9a9a9a; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  .card { background:var(--panel); border:1px solid #242424; border-radius:16px; padding:32px; width:min(380px,92vw);
          box-shadow:0 12px 40px rgba(0,0,0,.5); }
  h1 { margin:0 0 4px; font-size:22px; }
  h1 span { color:var(--magenta); }
  p.sub { margin:0 0 20px; color:var(--dim); font-size:13px; }
  label { display:block; font-size:12px; color:var(--dim); margin:14px 0 6px; }
  input { width:100%; padding:12px 14px; border-radius:10px; border:1px solid #333; background:#0d0d0d; color:var(--text); font-size:15px; }
  input:focus { outline:none; border-color:var(--magenta); }
  .row { display:flex; gap:10px; margin-top:22px; }
  button, a.btn { flex:1; text-align:center; text-decoration:none; padding:12px; border:0; border-radius:10px;
                  background:var(--magenta); color:#fff; font-size:15px; font-weight:600; cursor:pointer; }
  a.ghost { background:transparent; border:1px solid #333; color:var(--text); }
  button:hover { filter:brightness(1.08); }
  .err { margin-top:14px; color:#ff6b8a; font-size:13px; text-align:center; }
  .ok { margin-top:14px; color:#4caf50; font-size:14px; text-align:center; }
</style>
</head>
<body>
  <div class="card">
    <h1>Teamwork<span>Show</span></h1>
    <p class="sub">Neues Passwort festlegen</p>
    <?php if ($done): ?>
      <div class="ok">Passwort geändert.</div>
      <div class="row"><a class="btn" href="overview.php">Weiter zur Übersicht</a></div>
    <?php else: ?>
      <form method="post" action="change_password.php">
        <label for="password">Neues Passwort (min. 8 Zeichen)</label>
        <input id="password" name="password" type="password" autofocus autocomplete="new-password">
        <label for="password2">Passwort wiederholen</label>
        <input id="password2" name="password2" type="password" autocomplete="new-password">
        <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="row">
          <a class="btn ghost" href="overview.php">Abbrechen</a>
          <button type="submit">Speichern</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
