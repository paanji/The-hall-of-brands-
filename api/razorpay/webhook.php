<?php
require_once __DIR__ . '/../lib.php';

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!razorpay_verify_webhook_signature($payload, $signature)) {
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!$event || empty($event['event'])) {
    http_response_code(400);
    echo 'Bad payload';
    exit;
}

switch ($event['event']) {
    case 'payment.captured':
        $payment = $event['payload']['payment']['entity'] ?? [];
        $orderId = $payment['notes']['order_id'] ?? null;
        $amountMinor = (int) ($payment['amount'] ?? 0);
        if ($orderId) finalize_checkout($orderId, true, $amountMinor);
        break;

    case 'payment.failed':
        $payment = $event['payload']['payment']['entity'] ?? [];
        $orderId = $payment['notes']['order_id'] ?? null;
        if ($orderId) finalize_checkout($orderId, false);
        break;
}

http_response_code(200);
echo 'ok';
