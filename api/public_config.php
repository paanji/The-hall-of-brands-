<?php
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');
echo json_encode([
    'paypalClientId' => PAYPAL_CLIENT_ID,
]);
