<?php
require_once __DIR__ . '/../lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Invalid request method'], 405);
}

try {
    blocks_expire_pending();

    $brand = trim($_POST['brand'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $caption = trim($_POST['caption'] ?? '');
    $blocksRaw = $_POST['blocks'] ?? '[]';

    if ($brand === '' || $website === '' || $email === '') {
        json_response(['error' => 'Please fill in all fields.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['error' => 'That email address doesn\'t look right.'], 400);
    }
    if (mb_strlen($caption) > 120) {
        json_response(['error' => 'Hover text must be under 120 characters.'], 400);
    }
    if (!preg_match('~^https?://~i', $website)) $website = 'https://' . $website;
    if (!filter_var($website, FILTER_VALIDATE_URL)) {
        json_response(['error' => 'Please enter a valid website URL.'], 400);
    }

    $blocks = json_decode($blocksRaw, true);
    if (!is_array($blocks)) $blocks = [];
    $blocks = array_values(array_unique(array_map('intval', $blocks)));

    $reservation = reserve_blocks_for_checkout($blocks, $brand, $website, $email, $caption, $_FILES['logo'] ?? [], 'inr', 'razorpay');

    $order = razorpay_request('POST', '/orders', [
        'amount' => $reservation['amountMinor'],
        'currency' => 'INR',
        'receipt' => $reservation['orderId'],
        'notes' => ['order_id' => $reservation['orderId'], 'block_count' => count($blocks)],
    ]);

    json_response([
        'orderId' => $reservation['orderId'],
        'razorpayOrderId' => $order['id'],
        'razorpayKeyId' => RAZORPAY_KEY_ID,
        'amount' => $reservation['amountMinor'],
        'brand' => $brand,
        'email' => $email,
    ]);

} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    json_response(['error' => 'Something went wrong starting checkout. Please try again in a moment.'], 500);
}
