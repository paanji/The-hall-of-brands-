<?php
/**
 * Admin login credentials. Lives in .private/ (already denied from public
 * web access by the .htaccess in this folder — double-check that with the
 * curl test in the main README before relying on it).
 *
 * ADMIN_PASSWORD_HASH starts empty. Visit /admin/setup.php once to set your
 * username and password — it will refuse to run again once this is filled
 * in, and generates a proper bcrypt hash rather than storing your password
 * as plain text.
 */

define('ADMIN_USERNAME', '');
define('ADMIN_PASSWORD_HASH', '');

// Idle session timeout, in minutes. After this long with no activity, the
// admin is logged out automatically even if the browser tab stays open.
define('ADMIN_SESSION_IDLE_MINUTES', 30);

// Failed-login lockout: after this many wrong attempts, block further tries
// for this many minutes. Slows down anyone trying to brute-force the login.
define('ADMIN_LOGIN_MAX_ATTEMPTS', 5);
define('ADMIN_LOGIN_LOCKOUT_MINUTES', 15);
define('ADMIN_ATTEMPTS_PATH', __DIR__ . '/admin_attempts.json');
