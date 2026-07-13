<?php
/**
 * Dashboard login (email + password, session-based).
 *   GET                        -> branded login form
 *   POST email=.. password=..  -> verify against users; sets session, 302 -> overview.php
 *                                 (302 -> change_password.php when must_change_pw)
 *   GET ?logout=1              -> destroys the session, 302 -> login.php
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
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');
    $user = null;
    if ($email !== '' && $pass !== '') {
        try {
            $st = tw_db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            $user = $st->fetch() ?: null;
        } catch (Throwable $e) {
            $user = null; // e.g. table missing before migration
        }
    }
    if ($user && (int) $user['active'] === 1 && password_verify($pass, $user['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['tw_user_id'] = (int) $user['id'];
        $_SESSION['tw_role']    = $user['role'];
        $_SESSION['tw_email']   = $user['email'];
        if ((int) $user['must_change_pw'] === 1) {
            header('Location: change_password.php');
        } else {
            header('Location: overview.php');
        }
        exit;
    }
    http_response_code(401);
    $error = 'E-Mail oder Passwort falsch.';
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teamwork Show — Login</title>
<link rel="icon" type="image/png" sizes="64x64" href="assets/favicon.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<style>
  :root { --magenta:#e91e63; --bg:#0f172a; --panel:#1e293b; --line:#334155; --text:#f1f5f9; --dim:#94a3b8; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  body::after { content:""; position:fixed; right:32px; bottom:26px; width:min(320px,40vw); height:min(320px,40vw);
    background:url('assets/logo_mark.png') no-repeat right bottom; background-size:contain;
    opacity:.05; pointer-events:none; z-index:0; }
  .card { position:relative; z-index:1; background:var(--panel); border:1px solid var(--line); border-radius:16px;
          padding:32px; width:min(360px,92vw); box-shadow:0 12px 40px rgba(0,0,0,.45); }
  h1 { margin:0 0 4px; font-size:22px; }
  h1 span { color:var(--magenta); }
  p.sub { margin:0 0 24px; color:var(--dim); font-size:13px; }
  label { display:block; font-size:12px; color:var(--dim); margin:14px 0 6px; }
  input { width:100%; padding:12px 14px; border-radius:10px; border:1px solid var(--line); background:#0f172a; color:var(--text);
          font-size:15px; }
  input:focus { outline:none; border-color:var(--magenta); }
  button { margin-top:20px; width:100%; padding:12px; border:0; border-radius:10px; background:var(--magenta);
           color:#fff; font-size:15px; font-weight:600; cursor:pointer; }
  button:hover { filter:brightness(1.08); }
  .err { margin-top:14px; color:#ff6b8a; font-size:13px; text-align:center; }
</style>
</head>
<body>
  <form class="card" method="post" action="login.php">
    <h1>Teamwork<span>Show</span></h1>
    <p class="sub">Anmeldung</p>
    <label for="email">E-Mail</label>
    <input id="email" name="email" type="email" autofocus autocomplete="username" value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES) ?>">
    <label for="password">Passwort</label>
    <input id="password" name="password" type="password" autocomplete="current-password">
    <button type="submit">Anmelden</button>
    <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  </form>
</body>
</html>
