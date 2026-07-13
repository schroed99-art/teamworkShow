<?php
/**
 * Header user menu: a compact chip showing the signed-in user's e-mail that
 * opens a dropdown with self-service "Passwort ändern" and "Abmelden".
 * Include inside a dashboard <header> (session already started by auth.php).
 */
$twEmail   = (string) ($_SESSION['tw_email'] ?? '');
$twRole    = (string) ($_SESSION['tw_role'] ?? '');
$twInitial = strtoupper(substr(trim($twEmail), 0, 1) ?: '?');
?>
<div class="usermenu" id="usermenu">
  <button class="usermenu-btn" type="button" onclick="twToggleUserMenu(event)" aria-haspopup="true" aria-expanded="false">
    <span class="usermenu-avatar"><?= htmlspecialchars($twInitial) ?></span>
    <span class="usermenu-name"><?= htmlspecialchars($twEmail !== '' ? $twEmail : 'Konto') ?></span>
    <span class="usermenu-caret">▾</span>
  </button>
  <div class="usermenu-pop" role="menu">
    <?php if ($twEmail !== ''): ?>
    <div class="usermenu-head">
      <div class="usermenu-email"><?= htmlspecialchars($twEmail) ?></div>
      <?php if ($twRole !== ''): ?><div class="usermenu-role"><?= htmlspecialchars(ucfirst($twRole)) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <a href="change_password.php" role="menuitem">🔑 Passwort ändern</a>
    <a href="login.php?logout=1" role="menuitem" class="danger">↪ Abmelden</a>
  </div>
</div>
<style>
  .usermenu { position:relative; }
  .usermenu-btn { display:inline-flex; align-items:center; gap:8px; background:var(--panel,#1e293b); color:var(--text,#f1f5f9);
    border:1px solid var(--line,#334155); border-radius:999px; padding:5px 12px 5px 6px; font-size:13px; cursor:pointer; }
  .usermenu-btn:hover { border-color:var(--magenta,#d21a55); }
  .usermenu-avatar { width:24px; height:24px; border-radius:50%; background:var(--magenta,#d21a55); color:#fff;
    display:inline-flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex:none; }
  .usermenu-name { max-width:170px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .usermenu-caret { color:var(--dim,#94a3b8); font-size:11px; }
  .usermenu-pop { position:absolute; right:0; top:calc(100% + 6px); min-width:220px; background:var(--panel,#1e293b);
    border:1px solid var(--line,#334155); border-radius:12px; box-shadow:0 12px 40px rgba(0,0,0,.45); padding:6px; display:none; z-index:80; }
  .usermenu.open .usermenu-pop { display:block; }
  .usermenu-head { padding:8px 10px; border-bottom:1px solid var(--line,#334155); margin-bottom:6px; }
  .usermenu-email { font-size:12px; color:var(--text,#f1f5f9); overflow:hidden; text-overflow:ellipsis; }
  .usermenu-role { font-size:11px; color:var(--dim,#94a3b8); margin-top:2px; }
  .usermenu-pop a { display:block; padding:9px 10px; border-radius:8px; color:var(--text,#f1f5f9); text-decoration:none; font-size:13px; }
  .usermenu-pop a:hover { background:var(--panel2,#26344a); }
  .usermenu-pop a.danger:hover { color:#fff; background:var(--magenta,#d21a55); }
</style>
<script>
  function twToggleUserMenu(e){ e.stopPropagation(); document.getElementById('usermenu').classList.toggle('open'); }
  document.addEventListener('click', function(ev){ const m=document.getElementById('usermenu'); if(m && !m.contains(ev.target)) m.classList.remove('open'); });
</script>
