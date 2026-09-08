<?php
require_once __DIR__ . '/../.private/config.php';

/** Read blocks.json with a shared lock. Returns assoc array keyed by block number (as string). */
function blocks_read(): array {
    if (!file_exists(BLOCKS_JSON_PATH)) return [];
    $fh = fopen(BLOCKS_JSON_PATH, 'r');
    if (!$fh) return [];
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Read-modify-write blocks.json atomically. $mutator receives the current
 * array by reference and should mutate it in place. Returns the final array.
 */
function blocks_update(callable $mutator): array {
    $fh = fopen(BLOCKS_JSON_PATH, 'c+');
    if (!$fh) throw new RuntimeException('Cannot open blocks.json');
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];

    $mutator($data);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data;
}

/** Release any pending reservations whose TTL has expired, deleting their temp logos. */
function blocks_expire_pending(): void {
    blocks_update(function (array &$data) {
        $now = time();
        foreach ($data as $n => $info) {
            if (($info['status'] ?? null) === 'pending' && ($info['expiresAt'] ?? 0) < $now) {
                if (!empty($info['tmpLogoPath']) && file_exists($info['tmpLogoPath'])) {
                    @unlink($info['tmpLogoPath']);
                }
                unset($data[$n]);
            }
        }
    });
}

/** Server-side price calc for the given currency — never trust a client-supplied total. */
function calc_amount_minor(int $blockCount, string $currency): int {
    return $blockCount * ($currency === 'usd' ? PRICE_PER_BLOCK_USD_MINOR : PRICE_PER_BLOCK_INR_MINOR);
}

// ---------------------------------------------------------------------------
// Shared reservation / finalization — used by both Razorpay and PayPal flows
// so the block-locking, logo-upload, and sold-record logic only exists once.
// ---------------------------------------------------------------------------

/**
 * Validate + reserve a set of blocks as "pending" for a given gateway.
 * Returns ['orderId' => ..., 'amountMinor' => ...] on success, or throws
 * InvalidArgumentException with a user-facing message.
 */
function reserve_blocks_for_checkout(array $blocks, string $brand, string $website, string $email, string $caption, array $file, string $currency, string $gateway): array {
    if (count($blocks) === 0) {
        throw new InvalidArgumentException('Select at least one block first.');
    }
    if (count($blocks) > MAX_BLOCKS_PER_ORDER) {
        throw new InvalidArgumentException('That\'s more blocks than we allow in a single order (' . MAX_BLOCKS_PER_ORDER . ' max). Please split it into two orders.');
    }
    foreach ($blocks as $n) {
        if ($n < 1 || $n > GRID_SIZE * GRID_SIZE) {
            throw new InvalidArgumentException('Invalid block number.');
        }
    }

    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Please upload a block image.');
    }
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) throw new InvalidArgumentException('Image must be PNG, JPG, WEBP, or SVG.');
    if ($file['size'] > 3 * 1024 * 1024) throw new InvalidArgumentException('Image must be under 3MB.');

    $orderId = bin2hex(random_bytes(8));
    if (!is_dir(UPLOADS_PENDING_DIR)) mkdir(UPLOADS_PENDING_DIR, 0755, true);
    $tmpLogoPath = UPLOADS_PENDING_DIR . '/' . $orderId . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $tmpLogoPath)) {
        throw new InvalidArgumentException('Could not save the uploaded image. Please try again.');
    }

    $conflict = false;
    blocks_update(function (array &$data) use ($blocks, &$conflict, $orderId, $tmpLogoPath, $brand, $website, $email, $caption, $currency, $gateway) {
        foreach ($blocks as $n) {
            if (isset($data[$n])) { $conflict = true; return; }
        }
        $expiresAt = time() + PENDING_TTL_MINUTES * 60;
        foreach ($blocks as $n) {
            $data[$n] = [
                'status' => 'pending',
                'orderId' => $orderId,
                'expiresAt' => $expiresAt,
                'brand' => $brand,
                'website' => $website,
                'email' => $email,
                'caption' => $caption,
                'currency' => $currency,
                'gateway' => $gateway,
                'tmpLogoPath' => $tmpLogoPath,
            ];
        }
    });

    if ($conflict) {
        @unlink($tmpLogoPath);
        throw new InvalidArgumentException('One or more of your selected blocks were just taken by someone else. Please refresh and pick again.');
    }

    return [
        'orderId' => $orderId,
        'amountMinor' => calc_amount_minor(count($blocks), $currency),
    ];
}

/**
 * Mark a pending order's blocks as permanently sold (on confirmed payment)
 * or release them back to available (on failure/expiry/cancellation).
 * Idempotent — safe to call more than once for the same orderId (e.g. both
 * a client-side confirmation AND a webhook firing for the same payment).
 */
function finalize_checkout(string $orderId, bool $paid, int $amountPaidMinor = 0): void {
    if (!is_dir(UPLOADS_CLIENTS_DIR)) mkdir(UPLOADS_CLIENTS_DIR, 0755, true);

    blocks_update(function (array &$data) use ($orderId, $paid, $amountPaidMinor) {
        foreach ($data as $n => $info) {
            if (($info['orderId'] ?? null) !== $orderId) continue;
            if (($info['status'] ?? null) !== 'pending') continue; // already finalized — idempotent no-op

            if ($paid) {
                $finalPath = null;
                if (!empty($info['tmpLogoPath']) && file_exists($info['tmpLogoPath'])) {
                    $ext = pathinfo($info['tmpLogoPath'], PATHINFO_EXTENSION);
                    $finalRelative = $orderId . '.' . $ext;
                    $finalPath = UPLOADS_CLIENTS_DIR . '/' . $finalRelative;
                    rename($info['tmpLogoPath'], $finalPath);
                }
                $data[$n] = [
                    'status' => 'sold',
                    'imgSrc' => $finalPath ? UPLOADS_CLIENTS_URL . '/' . basename($finalPath) : '',
                    'link' => $info['website'] ?? '#',
                    'alt' => $info['brand'] ?? 'Client block',
                    'caption' => $info['caption'] ?? '',
                    'orderId' => $orderId,
                    'source' => $info['gateway'] ?? 'online',
                    'currency' => $info['currency'] ?? 'inr',
                    'email' => $info['email'] ?? '',
                    'amountPaidMinor' => $amountPaidMinor,
                    'soldAt' => time(),
                ];
            } else {
                if (!empty($info['tmpLogoPath']) && file_exists($info['tmpLogoPath'])) {
                    @unlink($info['tmpLogoPath']);
                }
                unset($data[$n]);
            }
        }
    });
}

// ---------------------------------------------------------------------------
// Generic HTTP helper
// ---------------------------------------------------------------------------

function http_request(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 20,
    ];
    if (!empty($opts['userpwd'])) $curlOpts[CURLOPT_USERPWD] = $opts['userpwd'];
    if (!empty($opts['form'])) $curlOpts[CURLOPT_POSTFIELDS] = http_build_query($opts['form']);
    if (!empty($opts['json'])) {
        $curlOpts[CURLOPT_POSTFIELDS] = json_encode($opts['json']);
        $headers[] = 'Content-Type: application/json';
    }
    $curlOpts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $curlOpts);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Request failed: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($response, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

// ---------------------------------------------------------------------------
// Razorpay
// ---------------------------------------------------------------------------

function razorpay_request(string $method, string $path, array $json = []): array {
    $res = http_request($method, 'https://api.razorpay.com/v1' . $path, [
        'userpwd' => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        'json' => $json,
    ]);
    if ($res['status'] >= 400) {
        throw new RuntimeException($res['body']['error']['description'] ?? 'Razorpay error');
    }
    return $res['body'];
}

/** Verify the signature Razorpay Checkout.js returns after a successful payment. */
function razorpay_verify_payment_signature(string $razorpayOrderId, string $razorpayPaymentId, string $signature): bool {
    $expected = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, RAZORPAY_KEY_SECRET);
    return hash_equals($expected, $signature);
}

/** Verify a Razorpay webhook's X-Razorpay-Signature header. */
function razorpay_verify_webhook_signature(string $payload, string $signature): bool {
    $expected = hash_hmac('sha256', $payload, RAZORPAY_WEBHOOK_SECRET);
    return hash_equals($expected, $signature);
}

// ---------------------------------------------------------------------------
// PayPal
// ---------------------------------------------------------------------------

function paypal_base_url(): string {
    return PAYPAL_MODE === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function paypal_access_token(): string {
    $res = http_request('POST', paypal_base_url() . '/v1/oauth2/token', [
        'userpwd' => PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET,
        'form' => ['grant_type' => 'client_credentials'],
    ]);
    if ($res['status'] >= 400 || empty($res['body']['access_token'])) {
        throw new RuntimeException('Could not authenticate with PayPal.');
    }
    return $res['body']['access_token'];
}

function paypal_request(string $method, string $path, array $json = []): array {
    $token = paypal_access_token();
    $res = http_request($method, paypal_base_url() . $path, [
        'headers' => ['Authorization: Bearer ' . $token],
        'json' => $json,
    ]);
    if ($res['status'] >= 400) {
        throw new RuntimeException($res['body']['message'] ?? 'PayPal error');
    }
    return $res['body'];
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
