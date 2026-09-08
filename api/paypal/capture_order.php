<?php
require_once __DIR__ . '/../lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Invalid request method'], 405);
}

try {
    $orderId = $_POST['orderId'] ?? '';
    $paypalOrderId = $_POST['paypalOrderId'] ?? '';

    if (!$orderId || !$paypalOrderId) {
        json_response(['error' => 'Missing payment confirmation details.'], 400);
    }

    $capture = paypal_request('POST', '/v2/checkout/orders/' . urlencode($paypalOrderId) . '/capture');

    $status = $capture['status'] ?? '';
    if ($status !== 'COMPLETED') {
        json_response(['error' => 'Payment was not completed.'], 400);
    }

    $captureUnit = $capture['purchase_units'][0]['payments']['captures'][0] ?? [];
    $amountDollars = (float) ($captureUnit['amount']['value'] ?? 0);
    $amountCents = (int) round($amountDollars * 100);

    finalize_checkout($orderId, true, $amountCents);

    json_response(['success' => true]);

} catch (Throwable $e) {
    json_response(['error' => 'Could not confirm payment. Please contact support if you were charged.'], 500);
}
