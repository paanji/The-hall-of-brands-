<?php
require_once __DIR__ . '/lib.php';
admin_require_login();

$orderId = $_GET['order'] ?? ($_POST['order'] ?? '');
if ($orderId === '') {
    header('Location: /admin/index.php');
    exit;
}

$error = '';
$success = '';

// ---- Handle POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'release') {
        blocks_update(function (array &$data) use ($orderId) {
            foreach ($data as $n => $info) {
                if (($info['orderId'] ?? null) !== $orderId) continue;
                if (!empty($info['tmpLogoPath']) && file_exists($info['tmpLogoPath'])) {
                    @unlink($info['tmpLogoPath']);
                }
                unset($data[$n]);
            }
        });
        header('Location: /admin/index.php');
        exit;
    }

    if ($action === 'remove') {
        blocks_update(function (array &$data) use ($orderId) {
            foreach ($data as $n => $info) {
                if (($info['orderId'] ?? null) !== $orderId) continue;
                if (!empty($info['imgSrc'])) {
                    $path = UPLOADS_CLIENTS_DIR . '/' . basename(parse_url($info['imgSrc'], PHP_URL_PATH));
                    if (file_exists($path)) @unlink($path);
                }
                unset($data[$n]);
            }
        });
        header('Location: /admin/index.php');
        exit;
    }

    if ($action === 'update') {
        $brand = trim($_POST['brand'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $caption = trim($_POST['caption'] ?? '');
        if ($brand === '' || $website === '') {
            $error = 'Brand and website are required.';
        } elseif (mb_strlen($caption) > 120) {
            $error = 'Hover text must be under 120 characters.';
        } else {
            if (!preg_match('~^https?://~i', $website)) $website = 'https://' . $website;

            $newImgSrc = null;
            if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
                $mime = mime_content_type($_FILES['logo']['tmp_name']);
                if (!isset($allowed[$mime])) {
                    $error = 'Logo must be PNG, JPG, WEBP, or SVG.';
                } elseif ($_FILES['logo']['size'] > 3 * 1024 * 1024) {
                    $error = 'Logo must be under 3MB.';
                } else {
                    if (!is_dir(UPLOADS_CLIENTS_DIR)) mkdir(UPLOADS_CLIENTS_DIR, 0755, true);
                    $finalPath = UPLOADS_CLIENTS_DIR . '/' . $orderId . '.' . $allowed[$mime];
                    move_uploaded_file($_FILES['logo']['tmp_name'], $finalPath);
                    $newImgSrc = UPLOADS_CLIENTS_URL . '/' . basename($finalPath);
                }
            }

            if (!$error) {
                blocks_update(function (array &$data) use ($orderId, $brand, $website, $caption, $newImgSrc) {
                    foreach ($data as $n => $info) {
                        if (($info['orderId'] ?? null) !== $orderId) continue;
                        $data[$n]['alt'] = $brand;
                        $data[$n]['link'] = $website;
                        $data[$n]['caption'] = $caption;
                        if ($newImgSrc) $data[$n]['imgSrc'] = $newImgSrc;
                    }
                });
                $success = 'Updated.';
            }
        }
    }
}

// ---- Load current order for display ----
$orders = admin_group_orders();
$order = $orders[$orderId] ?? null;
if (!$order) {
    admin_page_header('Not found');
    echo '<div class="admin-content"><p>That client/order was not found — it may already have been removed.</p><p><a href="/admin/index.php">Back to dashboard</a></p></div>';
    admin_page_footer();
    exit;
}

admin_page_header('Edit client');
?>
<div class="admin-nav">
  <span class="admin-brand">Hall of Brands — Admin</span>
  <nav>
    <a href="/admin/index.php">Dashboard</a>
    <a href="/admin/add_client.php">Add client</a>
    <a href="/" target="_blank">View site</a>
    <a href="/admin/logout.php">Log out</a>
  </nav>
</div>

<div class="admin-content admin-content-narrow">
  <p><a href="/admin/index.php">&larr; Back to dashboard</a></p>
  <h1>Edit client</h1>
  <p class="muted"><?= count($order['blocks']) ?> blocks · <?= htmlspecialchars(implode(', ', array_slice($order['blocks'], 0, 8))) ?><?= count($order['blocks']) > 8 ? '…' : '' ?></p>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <?php if ($order['imgSrc']): ?>
    <img class="thumb-large" src="<?= htmlspecialchars($order['imgSrc']) ?>" alt="">
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= admin_csrf_field() ?>
    <input type="hidden" name="order" value="<?= htmlspecialchars($orderId) ?>">
    <input type="hidden" name="action" value="update">

    <label>Brand name</label>
    <input type="text" name="brand" value="<?= htmlspecialchars($order['brand']) ?>" required>

    <label>Website link</label>
    <input type="text" name="website" value="<?= htmlspecialchars($order['link']) ?>" required>

    <label>Hover text (optional)</label>
    <input type="text" name="caption" maxlength="120" value="<?= htmlspecialchars($order['caption'] ?? '') ?>" placeholder="e.g. Mama's Touch — handmade skincare">

    <label>Replace block image (optional)</label>
    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">

    <button type="submit" class="btn-primary">Save changes</button>
  </form>

  <form method="post" class="danger-zone" onsubmit="return confirm('Remove this client and free their blocks? This cannot be undone.');">
    <?= admin_csrf_field() ?>
    <input type="hidden" name="order" value="<?= htmlspecialchars($orderId) ?>">
    <input type="hidden" name="action" value="remove">
    <button type="submit" class="btn-danger">Remove client &amp; free blocks</button>
  </form>
</div>
<?php admin_page_footer(); ?>
