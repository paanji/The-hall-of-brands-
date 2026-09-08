<?php
require_once __DIR__ . '/lib.php';

if (admin_is_logged_in()) {
    header('Location: /admin/index.php');
    exit;
}

if (ADMIN_PASSWORD_HASH === '') {
    header('Location: /admin/setup.php');
    exit;
}

$error = '';
$justCreated = isset($_GET['created']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (admin_is_locked_out()) {
        $mins = ceil(admin_lockout_seconds_remaining() / 60);
        $error = "Too many failed attempts. Try again in about {$mins} minute(s).";
    } else {
        admin_verify_csrf();
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (hash_equals(ADMIN_USERNAME, $username) && password_verify($password, ADMIN_PASSWORD_HASH)) {
            admin_clear_failed_logins();
            session_regenerate_id(true); // prevent session fixation
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_last_activity'] = time();
            header('Location: /admin/index.php');
            exit;
        } else {
            admin_register_failed_login();
            $error = 'Incorrect username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin login — Hall of Brands</title>
<link rel="stylesheet" href="/admin/style.css">
</head>
<body>
<div class="auth-box">
  <h1>Admin login</h1>
  <?php if ($justCreated): ?><div class="alert alert-success">Admin account created. Log in below.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if (admin_is_locked_out()): ?>
    <p class="muted">Login is temporarily locked after repeated failed attempts.</p>
  <?php else: ?>
    <form method="post">
      <?= admin_csrf_field() ?>
      <label>Username</label>
      <input type="text" name="username" required autocomplete="username" autofocus>
      <label>Password</label>
      <input type="password" name="password" required autocomplete="current-password">
      <button type="submit" class="btn-primary">Log in</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
