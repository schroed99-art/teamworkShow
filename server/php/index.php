<?php
/**
 * Server root. The public folder-scan grid was replaced by the login-guarded
 * tenant overview; media management moved into the admin. Just forward there —
 * overview.php redirects on to login.php when there is no admin session.
 */
header('Location: overview.php');
exit;
