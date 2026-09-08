<?php
require_once __DIR__ . '/lib.php';
admin_require_login();

$error = '';
$success = '';

function parse_block_spec(string $spec, int $max): array {
    $out = [];
    foreach (explode(',', $spec) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (strpos($part, '-') !== false) {
            [$a, $b] = array_map('trim', explode('-', $part, 2));
            if (!ctype_digit($a) || !ctype_digit($b)) throw new InvalidArgumentException("Invalid range: $part");
            [$a, $b] = [(int) $a, (int) $b];
            if ($a > $b) [$a, $b] = [$b, $a];
            for ($n = $a; $n <= $b; $n++) $out[] = $n;
        } else {
            if (!ctype_digit($part)) throw new InvalidArgumentException("Invalid block number: $part");
            $out[] = (int) $part;
        }
    }
    $out = array_values(array_unique($out));
    foreach ($out as $n) {
        if ($n < 1 || $n > $max) throw new InvalidArgumentException("Block $n is out of range (1–$max).");
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();

    $brand = trim($_POST['brand'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $caption = trim($_POST['caption'] ?? '');
    $blockSpec = trim($_POST['blocks'] ?? '');
    $amountRupees = trim($_POST['amount'] ?? '');

    try {
        if ($brand === '' || $website === '' || $blockSpec === '') {
            throw new InvalidArgumentException('Brand, website, and blocks are required.');
        }
        if (mb_strlen($caption) > 120) {
            throw new InvalidArgumentException('Hover text must be under 120 characters.');
        }
        if (!preg_match('~^https?://~i', $website)) $website = 'https://' . $website;

        $maxBlock = GRID_SIZE * GRID_SIZE;
        $blocks = parse_block_spec($blockSpec, $maxBlock);
        if (empty($blocks)) throw new InvalidArgumentException('No valid block numbers given.');
        if (count($blocks) > MAX_BLOCKS_PER_ORDER) {
            throw new InvalidArgumentException('That\'s more than the ' . MAX_BLOCKS_PER_ORDER . '-block order limit.');
        }

        if (empty($_FILES['logo']['name']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Please upload a logo image.');
        }
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        $mime = mime_content_type($_FILES['logo']['tmp_name']);
        if (!isset($allowed[$mime])) throw new InvalidArgumentException('Logo must be PNG, JPG, WEBP, or SVG.');
        if ($_FILES['logo']['size'] > 3 * 1024 * 1024) throw new InvalidArgumentException('Logo must be under 3MB.');

        $amountMinor = 0;
        if ($amountRupees !== '') {
            if (!is_numeric($amountRupees) || (float) $amountRupees < 0) {
                throw new InvalidArgumentException('Amount must be a positive number.');
            }
            $amountMinor = (int) round(((float) $amountRupees) * 100);
        }

        $orderId = bin2hex(random_bytes(8));
        if (!is_dir(UPLOADS_CLIENTS_DIR)) mkdir(UPLOADS_CLIENTS_DIR, 0755, true);
        $finalPath = UPLOADS_CLIENTS_DIR . '/' . $orderId . '.' . $allowed[$mime];
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $finalPath)) {
            throw new InvalidArgumentException('Could not save the logo file.');
        }
        $imgSrc = UPLOADS_CLIENTS_URL . '/' . basename($finalPath);

        $conflict = [];
        blocks_update(function (array &$data) use ($blocks, &$conflict, $orderId, $brand, $website, $email, $caption, $imgSrc, $amountMinor) {
            foreach ($blocks as $n) {
                if (isset($data[$n])) { $conflict[] = $n; }
            }
            if ($conflict) return;
            foreach ($blocks as $n) {
                $data[$n] = [
                    'status' => 'sold',
                    'imgSrc' => $imgSrc,
                    'link' => $website,
                    'alt' => $brand,
                    'caption' => $caption,
                    'orderId' => $orderId,
                    'source' => 'offline',
                    'currency' => 'inr',
                    'email' => $email,
                    'amountPaidMinor' => $amountMinor,
                    'soldAt' => time(),
                ];
            }
        });

        if ($conflict) {
            @unlink($finalPath);
            throw new InvalidArgumentException('These blocks are already taken: ' . implode(', ', array_slice($conflict, 0, 20)) . (count($conflict) > 20 ? '…' : ''));
        }

        header('Location: /admin/index.php');
        exit;

    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

admin_page_header('Add client');
?>
<div class="admin-nav">
  <span class="admin-brand">Hall of Brands — Admin</span>
  <nav>
    <a href="/admin/index.php">Dashboard</a>
    <a href="/admin/add_client.php" class="active">Add client</a>
    <a href="/" target="_blank">View site</a>
    <a href="/admin/logout.php">Log out</a>
  </nav>
</div>

<div class="admin-content admin-content-narrow">
  <p><a href="/admin/index.php">&larr; Back to dashboard</a></p>
  <h1>Add a client manually</h1>
  <p class="muted">For clients who paid you offline (bank transfer, UPI, cash). This marks their blocks sold immediately — no payment gateway involved.</p>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= admin_csrf_field() ?>

    <label>Block numbers</label>
    <input type="text" name="blocks" placeholder="e.g. 1000-1049, 2200, 2201" value="<?= htmlspecialchars($_POST['blocks'] ?? '') ?>" required>
    <p class="field-hint">Comma-separated numbers and/or ranges (a-b). Numbers 1 to <?= number_format(GRID_SIZE * GRID_SIZE) ?>.</p>

    <label>Brand name</label>
    <input type="text" name="brand" value="<?= htmlspecialchars($_POST['brand'] ?? '') ?>" required>

    <label>Website link</label>
    <input type="text" name="website" value="<?= htmlspecialchars($_POST['website'] ?? '') ?>" required>

    <label>Email (optional)</label>
    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

    <label>Hover text (optional)</label>
    <input type="text" name="caption" maxlength="120" value="<?= htmlspecialchars($_POST['caption'] ?? '') ?>" placeholder="e.g. Mama's Touch — handmade skincare">
    <p class="field-hint">Shown as a tooltip when someone hovers over the block. Leave blank to just use the brand name.</p>

    <label>Amount collected, in ₹ (optional, for your records)</label>
    <input type="text" name="amount" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" placeholder="e.g. 4250">

    <label>Block image</label>
    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" required>
    <p class="field-hint">Doesn't have to be a logo — any image works.</p>

    <button type="submit" class="btn-primary">Add client &amp; mark blocks sold</button>
  </form>
</div>
<?php admin_page_footer(); ?>
