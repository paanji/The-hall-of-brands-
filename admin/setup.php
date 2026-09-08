<?php
require_once __DIR__ . '/../.private/admin_config.php';

if (ADMIN_PASSWORD_HASH !== '') {
    http_response_code(403);
    die('Admin credentials are already set. Delete or edit .private/admin_config.php manually if you need to reset them — this page refuses to run again once a password exists, on purpose.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (strlen($username) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($password) < 10) {
        $error = 'Password must be at least 10 characters — this protects your client data and payment gateway integration, make it a real one.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $configPath = __DIR__ . '/../.private/admin_config.php';
        $content = file_get_contents($configPath);
        $content = str_replace(
            "define('ADMIN_USERNAME', '');",
            "define('ADMIN_USERNAME', " . var_export($username, true) . ");",
            $content
        );
        $content = str_replace(
            "define('ADMIN_PASSWORD_HASH', '');",
            "define('ADMIN_PASSWORD_HASH', " . var_export($hash, true) . ");",
            $content
        );
        file_put_contents($configPath, $content, LOCK_EX);
        header('Location: /admin/login.php?created=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin setup — Hall of Brands</title>
<link rel="stylesheet" href="/admin/style.css">
</head>
<body>
<div class="auth-box">
  <h1>Set up admin access</h1>
  <p class="muted">This runs once. Once you submit, this page disables itself permanently.</p>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <label>Username</label>
    <input type="text" name="username" required autocomplete="username">
    <label>Password (10+ characters)</label>
    <input type="password" name="password" required minlength="10" autocomplete="new-password">
    <label>Confirm password</label>
    <input type="password" name="confirm" required minlength="10" autocomplete="new-password">
    <button type="submit" class="btn-primary">Create admin account</button>
  </form>
</div>
</body>
</html>
