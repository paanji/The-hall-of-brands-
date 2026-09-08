<?php
require_once __DIR__ . '/../lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Invalid request method'], 405);
}

try {
    $orderId = $_POST['orderId'] ?? '';
    $razorpayOrderId = $_POST['razorpay_order_id'] ?? '';
    $razorpayPaymentId = $_POST['razorpay_payment_id'] ?? '';
    $signature = $_POST['razorpay_signature'] ?? '';

    if (!$orderId || !$razorpayOrderId || !$razorpayPaymentId || !$signature) {
        json_response(['error' => 'Missing payment confirmation details.'], 400);
    }

    if (!razorpay_verify_payment_signature($razorpayOrderId, $razorpayPaymentId, $signature)) {
        json_response(['error' => 'Payment could not be verified. If money was deducted, it will be refunded automatically.'], 400);
    }

    // Signature is valid, meaning Razorpay genuinely processed this payment.
    // Fetch the payment to get the exact captured amount for our records.
    $payment = razorpay_request('GET', '/payments/' . $razorpayPaymentId);
    $amountMinor = (int) ($payment['amount'] ?? 0);

    finalize_checkout($orderId, true, $amountMinor);

    json_response(['success' => true]);

} catch (Throwable $e) {
    json_response(['error' => 'Could not confirm payment. Please contact support if you were charged.'], 500);
}
