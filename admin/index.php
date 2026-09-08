<?php
require_once __DIR__ . '/lib.php';
admin_require_login();

$orders = admin_group_orders();
$sold = array_filter($orders, fn($o) => $o['status'] === 'sold');
$pending = array_filter($orders, fn($o) => $o['status'] === 'pending');

$totalBlocksSold = array_sum(array_map(fn($o) => count($o['blocks']), $sold));
$totalInrMinor = array_sum(array_map(fn($o) => ($o['currency'] ?? 'inr') === 'inr' ? ($o['amountPaidMinor'] ?? 0) : 0, $sold));
$totalUsdMinor = array_sum(array_map(fn($o) => ($o['currency'] ?? 'inr') === 'usd' ? ($o['amountPaidMinor'] ?? 0) : 0, $sold));
$totalBlocks = GRID_SIZE * GRID_SIZE;

admin_page_header('Dashboard');
?>
<div class="admin-nav">
  <span class="admin-brand">Hall of Brands — Admin</span>
  <nav>
    <a href="/admin/index.php" class="active">Dashboard</a>
    <a href="/admin/add_client.php">Add client</a>
    <a href="/" target="_blank">View site</a>
    <a href="/admin/logout.php">Log out</a>
  </nav>
</div>

<div class="admin-content">

  <div class="stat-cards">
    <div class="stat-card">
      <div class="stat-value"><?= number_format($totalBlocksSold) ?> / <?= number_format($totalBlocks) ?></div>
      <div class="stat-label">Blocks sold</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= admin_format_rupees($totalInrMinor) ?></div>
      <div class="stat-label">Revenue — India (Razorpay)</div>
    </div>
    <div class="stat-card">
      <div class="stat-value">$<?= number_format($totalUsdMinor / 100, 2) ?></div>
      <div class="stat-label">Revenue — International (PayPal)</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= count($sold) ?></div>
      <div class="stat-label">Clients on the wall</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= count($pending) ?></div>
      <div class="stat-label">Reserved (mid-checkout)</div>
    </div>
  </div>

  <h2>Clients</h2>
  <?php if (empty($sold)): ?>
    <p class="muted">No clients yet. Add one manually, or wait for the first online order.</p>
  <?php else: ?>
  <table class="admin-table">
    <thead>
      <tr>
        <th></th>
        <th>Brand</th>
        <th>Blocks</th>
        <th>Source</th>
        <th>Amount</th>
        <th>Date</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($sold as $o): ?>
      <tr>
        <td><?php if ($o['imgSrc']): ?><img class="thumb" src="<?= htmlspecialchars($o['imgSrc']) ?>" alt=""><?php endif; ?></td>
        <td>
          <div class="cell-title"><?= htmlspecialchars($o['brand'] ?: '(no name)') ?></div>
          <?php if ($o['link']): ?><a class="cell-sub" href="<?= htmlspecialchars($o['link']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($o['link']) ?></a><?php endif; ?>
        </td>
        <td><?= count($o['blocks']) ?> blocks</td>
        <td><span class="badge badge-<?= htmlspecialchars($o['source']) ?>"><?= htmlspecialchars($o['source']) ?></span></td>
        <td><?= $o['amountPaidMinor'] ? admin_format_amount($o['amountPaidMinor'], $o['currency'] ?? 'inr') : '—' ?></td>
        <td><?= $o['soldAt'] ? date('d M Y', $o['soldAt']) : '—' ?></td>
        <td class="actions">
          <a href="/admin/edit_client.php?order=<?= urlencode($o['orderId']) ?>">Edit</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if (!empty($pending)): ?>
  <h2>Reserved (mid-checkout)</h2>
  <table class="admin-table">
    <thead>
      <tr><th>Brand</th><th>Blocks</th><th>Expires</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($pending as $o): ?>
      <tr>
        <td><?= htmlspecialchars($o['brand'] ?: '(no name)') ?></td>
        <td><?= count($o['blocks']) ?> blocks</td>
        <td><?= $o['soldAt'] ? date('d M Y, H:i', $o['soldAt']) : '—' ?></td>
        <td class="actions">
          <form method="post" action="/admin/edit_client.php" onsubmit="return confirm('Release these blocks back to available now?');">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="order" value="<?= htmlspecialchars($o['orderId']) ?>">
            <input type="hidden" name="action" value="release">
            <button type="submit" class="link-btn">Release now</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

</div>
<?php admin_page_footer(); ?>
